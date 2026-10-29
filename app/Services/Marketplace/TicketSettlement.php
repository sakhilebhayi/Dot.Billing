<?php

namespace App\Services\Marketplace;

use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\HostDebt;
use App\Models\PaymentEvent;
use App\Models\TicketDispute;
use App\Models\TicketPayment;
use App\Models\TicketRefund;
use App\Services\Billing\AuditLog;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackSettlement;
use Illuminate\Database\Eloquent\Collection;
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
 *  - anything else (mismatch, a payment for a cancelled ticket, a refund above what is still refundable)
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
        private HostDebts $debts,
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
        $debtEvents = [];

        $outcome = DB::transaction(function () use ($ticket, $tx, &$paid, &$debtEvents) {
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

            if (in_array($locked->status, TicketPayment::PAID_STATES, true)) {
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
                'recovery_cents' => $locked->recovery_cents,
                'currency' => $locked->currency,
            ]);

            // The ticket's larger fee (if it carried one) is credited to the host's debt now, in this same
            // transaction, and only here: a replay returned 'replayed' above and never reaches this line.
            $debtEvents = $this->debts->applyToPaidTicket($locked);

            $paid = $locked;

            return 'applied';
        });

        if ($paid !== null) {
            $this->callbacks->ticketPaid($paid);
            $this->tellInfoDot($debtEvents);
        }

        return $outcome;
    }

    /**
     * Paystack's refund.processed for a ticket's transaction. It is matched to our ticket_refunds row by
     * Paystack's refund id (or, failing that, the one pending refund of the same amount); a refund made in the
     * Paystack dashboard has no row and gets one. A refund is applied only if it is for a paid ticket, its own
     * status is "processed", its currency is the ticket's, and it does not exceed what is still refundable;
     * otherwise it is held for an operator and nothing changes. A replay is a no-op.
     *
     * @param  list<string>  $ids  Paystack's identifiers for this refund (id, refund_reference)
     * @param  array<string, mixed>  $payload  what to keep of the webhook body
     */
    public function settleRefund(TicketPayment $ticket, array $ids, string $key, int $amountCents, string $currency, ?string $providerStatus, array $payload): string
    {
        $applied = null;
        $debtEvents = [];

        $outcome = DB::transaction(function () use ($ticket, $ids, $key, $amountCents, $currency, $providerStatus, $payload, &$applied, &$debtEvents) {
            $locked = TicketPayment::lockForUpdate()->findOrFail($ticket->id);
            $row = $this->matchRefund($locked, $ids, $amountCents);

            if ($row && $row->status === TicketRefund::PROCESSED) {
                return 'replayed';
            }

            $reason = match (true) {
                $providerStatus !== null && strtolower($providerStatus) !== 'processed' => 'refund_not_processed',
                ! in_array($locked->status, TicketPayment::PAID_STATES, true) => 'refund_for_unpaid_ticket',
                strtoupper($currency) !== strtoupper($locked->currency) => 'refund_currency_mismatch',
                $amountCents < 1 || ($row && $row->amount_cents !== $amountCents) => 'refund_amount_mismatch',
                ! $row && $this->pendingRefunds($locked, $amountCents)->count() > 1 => 'refund_ambiguous',
                $amountCents > $locked->amount_cents - $locked->refunded_cents => 'refund_exceeds_remaining',
                default => null,
            };

            if ($reason !== null) {
                return $this->hold($locked, $key, $reason, PaymentEvent::NEEDS_REVIEW, $amountCents, $currency, $payload, 'ticket_refund');
            }

            // A refund nobody asked us for (started in the Paystack dashboard) is recorded under Paystack's own id.
            $row ??= $locked->refunds()->create([
                'refund_key' => 'paystack-'.substr($ids[0] ?? sha1($key), 0, 50),
                'amount_cents' => $amountCents,
                'status' => TicketRefund::PENDING,
            ]);

            $row->forceFill([
                'status' => TicketRefund::PROCESSED,
                'processed_at' => now(),
                'paystack_refund_id' => $row->paystack_refund_id ?? ($ids[0] ?? null),
            ])->save();

            $locked->refunded_cents += $amountCents;
            $locked->refunded_at = now();

            // A dispute in progress (or lost) keeps its own status; the refund is still counted.
            if (in_array($locked->status, [TicketPayment::PAID, TicketPayment::PARTIALLY_REFUNDED, TicketPayment::REFUNDED], true)) {
                $locked->status = $locked->restingStatus();
            }

            $locked->save();

            $this->audit->record('ticket.refunded', null, null, $locked, [
                'ticket_ref' => $locked->ticket_ref,
                'reference' => $locked->paystack_reference,
                'refund_key' => $row->refund_key,
                'refund_amount_cents' => $amountCents,
                'refunded_total_cents' => $locked->refunded_cents,
                'amount_cents' => $locked->amount_cents,
                'currency' => $locked->currency,
                'status' => $locked->status,
            ]);

            // A refund gives back its share of any recovery that was credited to the host's debt.
            $debtEvents = $this->debts->reverseForRefund($locked);

            $applied = [$locked, $row];

            return 'applied';
        });

        if ($applied !== null) {
            $this->callbacks->ticketRefunded(...$applied);
            $this->tellInfoDot($debtEvents);
        }

        return $outcome;
    }

    /**
     * Paystack's refund.failed: the pending refund we asked for is marked failed, so that amount is refundable
     * again and the same refund_key may be asked for again. Anything we cannot match is ignored.
     *
     * @param  list<string>  $ids
     */
    public function settleRefundFailed(TicketPayment $ticket, array $ids, int $amountCents): string
    {
        return DB::transaction(function () use ($ticket, $ids, $amountCents) {
            $locked = TicketPayment::lockForUpdate()->findOrFail($ticket->id);
            $row = $this->matchRefund($locked, $ids, $amountCents);

            if (! $row || $row->status !== TicketRefund::PENDING) {
                return 'ignored';
            }

            $row->forceFill(['status' => TicketRefund::FAILED])->save();

            $this->audit->record('ticket.refund_failed', null, null, $locked, [
                'ticket_ref' => $locked->ticket_ref,
                'reference' => $locked->paystack_reference,
                'refund_key' => $row->refund_key,
                'refund_amount_cents' => $row->amount_cents,
            ]);

            return 'applied';
        });
    }

    /**
     * Paystack's charge.dispute.create. Recorded only: a ticket_disputes row, status "disputed" while it is open
     * (from paid or partially_refunded), an audit entry and a ticket.disputed callback. A dispute on a ticket that
     * was never paid is held for an operator. Billing never answers or resolves a dispute.
     *
     * @param  array<string, mixed>  $data
     */
    public function settleDisputeCreated(TicketPayment $ticket, string $disputeId, array $data): string
    {
        $created = null;

        $outcome = DB::transaction(function () use ($ticket, $disputeId, $data, &$created) {
            $locked = TicketPayment::lockForUpdate()->findOrFail($ticket->id);

            if ($locked->disputes()->where('paystack_dispute_id', $disputeId)->exists()) {
                return 'replayed';
            }

            if (! in_array($locked->status, TicketPayment::PAID_STATES, true)) {
                return $this->hold($locked, 'dispute:'.$disputeId, 'dispute_for_unpaid_ticket', PaymentEvent::NEEDS_REVIEW, $locked->amount_cents, $locked->currency, ['event' => 'charge.dispute.create', 'dispute_id' => $disputeId], 'ticket_dispute');
            }

            $dispute = $locked->disputes()->create([
                'paystack_dispute_id' => $disputeId,
                'status' => TicketDispute::OPEN,
                'opened_at' => $this->parseTime(is_string($data['created_at'] ?? null) ? $data['created_at'] : null),
            ]);

            if (in_array($locked->status, [TicketPayment::PAID, TicketPayment::PARTIALLY_REFUNDED], true)) {
                $locked->forceFill(['status' => TicketPayment::DISPUTED])->save();
            }

            $this->audit->record('ticket.disputed', null, null, $locked, [
                'ticket_ref' => $locked->ticket_ref,
                'reference' => $locked->paystack_reference,
                'dispute_id' => $disputeId,
                'amount_cents' => $locked->amount_cents,
                'currency' => $locked->currency,
                'status' => $locked->status,
            ]);

            $created = [$locked, $dispute];

            return 'applied';
        });

        if ($created !== null) {
            $this->callbacks->ticketDisputed(...$created);
        }

        return $outcome;
    }

    /**
     * Paystack's charge.dispute.resolve. Won returns a disputed ticket to its resting status (paid,
     * partially_refunded or refunded); lost sets "chargeback". Any other result is not guessed at: the dispute is
     * recorded as resolved with outcome "other", the ticket stays "disputed" (so it still cannot be refunded) and
     * the event is held for an operator. A resolve for a dispute we never saw opened creates the row resolved.
     *
     * @param  array<string, mixed>  $data
     */
    public function settleDisputeResolved(TicketPayment $ticket, string $disputeId, array $data): string
    {
        $resolved = null;
        $debtEvents = [];

        $outcome = DB::transaction(function () use ($ticket, $disputeId, $data, &$resolved, &$debtEvents) {
            $locked = TicketPayment::lockForUpdate()->findOrFail($ticket->id);
            $dispute = $locked->disputes()->where('paystack_dispute_id', $disputeId)->first();

            if ($dispute && $dispute->status === TicketDispute::RESOLVED) {
                return 'replayed';
            }

            if (! $dispute && ! in_array($locked->status, TicketPayment::PAID_STATES, true)) {
                return $this->hold($locked, 'dispute:'.$disputeId, 'dispute_for_unpaid_ticket', PaymentEvent::NEEDS_REVIEW, $locked->amount_cents, $locked->currency, ['event' => 'charge.dispute.resolve', 'dispute_id' => $disputeId], 'ticket_dispute');
            }

            $raw = is_string($data['resolution'] ?? null) ? substr($data['resolution'], 0, 64) : null;
            $result = self::normaliseResolution($raw);

            $dispute ??= $locked->disputes()->create([
                'paystack_dispute_id' => $disputeId,
                'status' => TicketDispute::OPEN,
                'opened_at' => $this->parseTime(is_string($data['created_at'] ?? null) ? $data['created_at'] : null),
            ]);

            $dispute->forceFill([
                'status' => TicketDispute::RESOLVED,
                'resolution' => $result,
                'paystack_resolution' => $raw,
                'resolved_at' => $this->parseTime(is_string($data['resolved_at'] ?? null) ? $data['resolved_at'] : null),
            ])->save();

            if ($result === TicketDispute::LOST) {
                $locked->forceFill(['status' => TicketPayment::CHARGEBACK])->save();
                // InfoDot lost the host's share: record it as a debt, once per ticket (nothing for won or other).
                $debtEvents = $this->debts->openForLostDispute($locked);
            } elseif ($result === TicketDispute::WON && $locked->status === TicketPayment::DISPUTED) {
                $locked->forceFill(['status' => $locked->restingStatus()])->save();
            }

            $this->audit->record('ticket.dispute_resolved', null, null, $locked, [
                'ticket_ref' => $locked->ticket_ref,
                'reference' => $locked->paystack_reference,
                'dispute_id' => $disputeId,
                'outcome' => $result,
                'status' => $locked->status,
            ]);

            if ($result === TicketDispute::OTHER) {
                $this->hold($locked, 'dispute:'.$disputeId, 'dispute_outcome_unknown', PaymentEvent::NEEDS_REVIEW, $locked->amount_cents, $locked->currency, ['event' => 'charge.dispute.resolve', 'dispute_id' => $disputeId, 'resolution' => $raw], 'ticket_dispute');
            }

            $resolved = [$locked, $dispute];

            return 'applied';
        });

        if ($resolved !== null) {
            $this->callbacks->ticketDisputeResolved(...$resolved);
            $this->tellInfoDot($debtEvents);
        }

        return $outcome;
    }

    /**
     * @param  list<array{0: string, 1: HostDebt}>  $events
     */
    private function tellInfoDot(array $events): void
    {
        foreach ($events as [$event, $debt]) {
            $this->callbacks->hostDebt($event, $debt);
        }
    }

    /**
     * Paystack's resolution text as won, lost or other. "declined" (the dispute was declined, the merchant keeps
     * the money) is won; "merchant-accepted" (the merchant accepted the chargeback) is lost. These two values are
     * from Paystack's documentation and have NOT been checked against a real dispute; everything else is "other"
     * and goes to an operator.
     */
    public static function normaliseResolution(?string $resolution): string
    {
        return match (strtolower(trim((string) $resolution))) {
            'declined', 'won' => TicketDispute::WON,
            'merchant-accepted', 'lost' => TicketDispute::LOST,
            default => TicketDispute::OTHER,
        };
    }

    /**
     * @param  list<string>  $ids
     */
    private function matchRefund(TicketPayment $ticket, array $ids, int $amountCents): ?TicketRefund
    {
        if ($ids !== [] && $row = $ticket->refunds()->whereIn('paystack_refund_id', $ids)->first()) {
            return $row;
        }

        // Paystack's id for the refund may not be the one its refund API gave us: fall back to the single
        // pending refund of the same amount, and none at all if that is ambiguous.
        $pending = $this->pendingRefunds($ticket, $amountCents);

        return $pending->count() === 1 ? $pending->first() : null;
    }

    /**
     * @return Collection<int, TicketRefund>
     */
    private function pendingRefunds(TicketPayment $ticket, int $amountCents)
    {
        return $ticket->refunds()->where('status', TicketRefund::PENDING)->where('amount_cents', $amountCents)->get();
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
