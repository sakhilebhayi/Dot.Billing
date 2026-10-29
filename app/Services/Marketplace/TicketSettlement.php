<?php

namespace App\Services\Marketplace;

use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\PaymentEvent;
use App\Models\TicketPayment;
use App\Services\Billing\AuditLog;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single place a Paystack event becomes (or fails to become) a paid or refunded ticket. Called from the
 * Paystack webhook for references that belong to a ticket.
 *
 *  - charge.success is never trusted on its own: the transaction is VERIFIED with Paystack, and only a
 *    "success" whose reference, amount and currency equal what the ticket asked for marks it paid;
 *  - the ticket row is locked, so two deliveries run one after the other, and a replay is a no-op;
 *  - anything else (mismatch, a payment for a cancelled ticket, a refund we cannot match to the full amount)
 *    is written as an operator-visible held PaymentEvent with a reason, changes nothing on the ticket, and the
 *    webhook still answers 200. The one exception: if Paystack cannot be reached to verify, the answer is
 *    "retry" and the webhook answers 503 so Paystack delivers it again.
 *
 * Outcomes: applied, replayed, held, retry.
 */
class TicketSettlement
{
    public function __construct(
        private PaystackClient $client,
        private PaystackSettlement $held,
        private MarketplaceCallbackClient $callbacks,
        private MarketplaceConfig $config,
        private AuditLog $audit,
    ) {}

    /** The ticket a Paystack reference belongs to; always null while the marketplace is off. */
    public function findByReference(string $reference): ?TicketPayment
    {
        return $this->config->enabled()
            ? TicketPayment::where('paystack_reference', $reference)->first()
            : null;
    }

    public function settleCharge(TicketPayment $ticket): string
    {
        try {
            $tx = $this->client->verifyTransaction($ticket->paystack_reference);
        } catch (PaymentGatewayException) {
            return 'retry';
        }

        $paid = null;

        $outcome = DB::transaction(function () use ($ticket, $tx, &$paid) {
            $locked = TicketPayment::lockForUpdate()->findOrFail($ticket->id);

            $mismatch = match (true) {
                ! hash_equals($locked->paystack_reference, $tx->reference) => 'reference_mismatch',
                $tx->amountCents !== $locked->amount_cents => 'amount_mismatch',
                $tx->currency !== strtoupper($locked->currency) => 'currency_mismatch',
                default => null,
            };

            if ($mismatch !== null) {
                return $this->hold($locked, $tx->id, $mismatch, PaymentEvent::UNMATCHED, $tx->amountCents, $tx->currency, $tx->safePayload('charge.success'));
            }

            if (! $tx->isSuccessful()) {
                return $this->hold($locked, $tx->id, 'status_not_success', PaymentEvent::UNMATCHED, $tx->amountCents, $tx->currency, $tx->safePayload('charge.success'));
            }

            if (in_array($locked->status, [TicketPayment::PAID, TicketPayment::REFUNDED], true)) {
                return 'replayed';
            }

            if ($locked->status === TicketPayment::FAILED) {
                Log::warning('marketplace.ticket.late_payment', ['ticket_ref' => $locked->ticket_ref, 'reference' => $locked->paystack_reference]);

                return $this->hold($locked, $tx->id, 'ticket_cancelled', PaymentEvent::NEEDS_REVIEW, $tx->amountCents, $tx->currency, $tx->safePayload('charge.success'));
            }

            $locked->forceFill([
                'status' => TicketPayment::PAID,
                'paid_at' => $this->parseTime($tx->paidAt),
                'paystack_transaction_id' => $tx->id,
            ])->save();

            $this->audit->record('ticket.paid', null, null, $locked, [
                'ticket_ref' => $locked->ticket_ref,
                'reference' => $locked->paystack_reference,
                'transaction_id' => $tx->id,
                'amount_cents' => $locked->amount_cents,
                'platform_fee_cents' => $locked->platform_fee_cents,
                'currency' => $locked->currency,
            ]);

            $paid = $locked;

            return 'applied';
        });

        if ($paid !== null) {
            $this->callbacks->ticketPaid($paid);
        }

        return $outcome;
    }

    /**
     * Paystack's refund.processed for a ticket's transaction. Only a full refund of a paid ticket marks it
     * refunded (partial refunds are not modelled); anything else is held.
     *
     * @param  array<string, mixed>  $payload  what to keep of the webhook body
     */
    public function settleRefund(TicketPayment $ticket, string $key, int $amountCents, string $currency, ?string $providerStatus, array $payload): string
    {
        $refunded = null;

        $outcome = DB::transaction(function () use ($ticket, $key, $amountCents, $currency, $providerStatus, $payload, &$refunded) {
            $locked = TicketPayment::lockForUpdate()->findOrFail($ticket->id);

            if ($locked->status === TicketPayment::REFUNDED) {
                return 'replayed';
            }

            $reason = match (true) {
                $locked->status !== TicketPayment::PAID => 'refund_for_unpaid_ticket',
                $providerStatus !== null && strtolower($providerStatus) !== 'processed' => 'refund_not_processed',
                $amountCents !== $locked->amount_cents || strtoupper($currency) !== strtoupper($locked->currency) => 'refund_amount_mismatch',
                default => null,
            };

            if ($reason !== null) {
                return $this->hold($locked, $key, $reason, PaymentEvent::NEEDS_REVIEW, $amountCents, $currency, $payload, 'ticket_refund');
            }

            $locked->forceFill(['status' => TicketPayment::REFUNDED, 'refunded_at' => now()])->save();

            $this->audit->record('ticket.refunded', null, null, $locked, [
                'ticket_ref' => $locked->ticket_ref,
                'reference' => $locked->paystack_reference,
                'amount_cents' => $locked->amount_cents,
                'currency' => $locked->currency,
            ]);

            $refunded = $locked;

            return 'applied';
        });

        if ($refunded !== null) {
            $this->callbacks->ticketRefunded($refunded);
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hold(TicketPayment $ticket, string $key, string $reason, string $status, int $amountCents, string $currency, array $payload, string $type = 'ticket_payment'): string
    {
        $event = $this->held->holdUnknown($type, $key, $reason, $amountCents, $currency, $payload + ['ticket_ref' => $ticket->ticket_ref], $status);

        // A redelivery of an event already held finds the stored row and adds no second audit entry.
        if ($event->wasRecentlyCreated) {
            $this->audit->record('ticket.held', null, null, $ticket, [
                'ticket_ref' => $ticket->ticket_ref,
                'event_id' => $event->id,
                'reason' => $reason,
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'reference' => $ticket->paystack_reference,
            ]);
        }

        return 'held';
    }

    private function parseTime(?string $time): Carbon
    {
        try {
            return $time !== null ? Carbon::parse($time) : now();
        } catch (Throwable) {
            return now();
        }
    }
}
