<?php

namespace App\Services\Marketplace;

use App\Exceptions\Payments\MarketplaceException;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\PayoutAccount;
use App\Models\TicketPayment;
use App\Services\Billing\AuditLog;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create, refund and cancel seminar tickets.
 *
 * create() is idempotent on ticket_ref. A repeat with the same fields returns the stored checkout and makes
 * no second Paystack call; the same ref with different fields is 409 ticket_conflict; a paid (or refunded)
 * ref is 409 already_paid; a cancelled ref is 409 ticket_cancelled and can never be paid.
 *
 * If Paystack cannot be reached during create the whole transaction rolls back: no ticket row remains.
 * Nothing here ever marks a ticket paid or refunded. Only TicketSettlement does, after verifying with Paystack.
 */
class SeminarTickets
{
    public const CANCELLED = 'cancelled';

    public function __construct(
        private PaystackConfig $paystack,
        private PaystackClient $client,
        private MarketplaceConfig $config,
        private AuditLog $audit,
    ) {}

    public function find(string $ticketRef): ?TicketPayment
    {
        return TicketPayment::where('ticket_ref', $ticketRef)->first();
    }

    /**
     * @param  array{ticket_ref: string, payout_account_id: int, buyer_email: string, amount_cents: int, currency: string, description: string, return_url: ?string}  $input
     * @return array{0: TicketPayment, 1: bool} the ticket, and whether it was newly created
     *
     * @throws MarketplaceException
     */
    public function create(array $input): array
    {
        if (! $this->paystack->canTakePayments()) {
            throw MarketplaceException::providerUnavailable();
        }

        if ($existing = $this->find($input['ticket_ref'])) {
            return [$this->repeat($existing, $input), false];
        }

        $account = PayoutAccount::find($input['payout_account_id']);

        if (! $account || ! $account->isActive()) {
            throw MarketplaceException::because('payout_account_unavailable', 'That payout account does not exist or is not active.', 422);
        }

        try {
            $ticket = DB::transaction(function () use ($input, $account) {
                $ticket = TicketPayment::create([
                    'ticket_ref' => $input['ticket_ref'],
                    'payout_account_id' => $account->id,
                    'buyer_email' => $input['buyer_email'],
                    'amount_cents' => $input['amount_cents'],
                    'platform_fee_cents' => $this->config->platformFeeCents($input['amount_cents']),
                    'currency' => $input['currency'],
                    'description' => $input['description'],
                    'return_url' => $input['return_url'],
                    'paystack_reference' => 'dbt-'.Str::lower((string) Str::ulid()),
                    'status' => TicketPayment::PENDING,
                ]);

                $checkout = $this->client->initializeTicketTransaction($ticket, $account->paystack_subaccount_code);

                $ticket->forceFill(['authorization_url' => $checkout->authorizationUrl])->save();

                $this->audit->record('ticket.created', null, null, $ticket, [
                    'ticket_ref' => $ticket->ticket_ref,
                    'payout_account_id' => $account->id,
                    'reference' => $ticket->paystack_reference,
                    'amount_cents' => $ticket->amount_cents,
                    'platform_fee_cents' => $ticket->platform_fee_cents,
                    'currency' => $ticket->currency,
                ]);

                return $ticket;
            });
        } catch (UniqueConstraintViolationException) {
            // A simultaneous identical request won the insert: answer from its row.
            return [$this->repeat($this->find($input['ticket_ref']) ?? throw MarketplaceException::providerUnavailable(), $input), false];
        } catch (PaymentGatewayException $e) {
            throw $e->reason === 'rejected'
                ? MarketplaceException::because('payment_provider_rejected', 'The payment provider did not accept that ticket. Nothing was charged.', 502)
                : MarketplaceException::providerUnavailable();
        }

        return [$ticket, true];
    }

    /**
     * Ask Paystack to refund a paid ticket in full. The ticket stays "paid" (with refund_requested_at set) until
     * Paystack's refund.processed webhook arrives. Asking again changes nothing and calls Paystack no more.
     *
     * @throws MarketplaceException
     */
    public function refund(string $ticketRef): TicketPayment
    {
        if (! $this->paystack->canTakePayments()) {
            throw MarketplaceException::providerUnavailable();
        }

        return DB::transaction(function () use ($ticketRef) {
            $ticket = TicketPayment::where('ticket_ref', $ticketRef)->lockForUpdate()->first()
                ?? throw MarketplaceException::because('not_found', 'No such ticket.', 404);

            if ($ticket->status === TicketPayment::REFUNDED || ($ticket->status === TicketPayment::PAID && $ticket->refund_requested_at !== null)) {
                return $ticket;
            }

            if ($ticket->status !== TicketPayment::PAID) {
                throw MarketplaceException::because('ticket_not_refundable', "A {$ticket->status} ticket cannot be refunded. Cancel it instead if it is unpaid.", 409);
            }

            try {
                $status = $this->client->refundTransaction($ticket->paystack_reference);
            } catch (PaymentGatewayException $e) {
                throw $e->reason === 'rejected'
                    ? MarketplaceException::because('refund_rejected', 'The payment provider did not accept the refund. Nothing was changed.', 422)
                    : MarketplaceException::providerUnavailable();
            }

            $ticket->forceFill(['refund_requested_at' => now()])->save();

            $this->audit->record('ticket.refund_requested', null, null, $ticket, [
                'ticket_ref' => $ticket->ticket_ref,
                'reference' => $ticket->paystack_reference,
                'amount_cents' => $ticket->amount_cents,
                'currency' => $ticket->currency,
                'provider_status' => $status,
            ]);

            return $ticket;
        });
    }

    /**
     * Cancel an unpaid ticket: it becomes "failed" and can never be paid. A payment that still arrives is held
     * for an operator by TicketSettlement. Cancelling twice is fine; a paid or refunded ticket cannot be cancelled.
     *
     * @throws MarketplaceException
     */
    public function cancel(string $ticketRef): TicketPayment
    {
        return DB::transaction(function () use ($ticketRef) {
            $ticket = TicketPayment::where('ticket_ref', $ticketRef)->lockForUpdate()->first()
                ?? throw MarketplaceException::because('not_found', 'No such ticket.', 404);

            if ($ticket->status === TicketPayment::FAILED && $ticket->failure_reason === self::CANCELLED) {
                return $ticket;
            }

            if ($ticket->status !== TicketPayment::PENDING) {
                throw MarketplaceException::because('ticket_not_cancellable', "A {$ticket->status} ticket cannot be cancelled.", 409);
            }

            $ticket->forceFill(['status' => TicketPayment::FAILED, 'failure_reason' => self::CANCELLED])->save();

            $this->audit->record('ticket.cancelled', null, null, $ticket, [
                'ticket_ref' => $ticket->ticket_ref,
                'reference' => $ticket->paystack_reference,
                'amount_cents' => $ticket->amount_cents,
                'currency' => $ticket->currency,
            ]);

            return $ticket;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws MarketplaceException
     */
    private function repeat(TicketPayment $existing, array $input): TicketPayment
    {
        if (in_array($existing->status, [TicketPayment::PAID, TicketPayment::REFUNDED], true)) {
            throw MarketplaceException::because('already_paid', 'That ticket has already been paid.', 409);
        }

        if ($existing->status === TicketPayment::FAILED) {
            throw MarketplaceException::because('ticket_cancelled', 'That ticket was cancelled and cannot be paid. Use a new ticket_ref.', 409);
        }

        $same = $existing->payout_account_id === $input['payout_account_id']
            && $existing->buyer_email === $input['buyer_email']
            && $existing->amount_cents === $input['amount_cents']
            && $existing->currency === $input['currency']
            && $existing->description === $input['description']
            && $existing->return_url === $input['return_url'];

        if (! $same || ! $existing->authorization_url) {
            throw MarketplaceException::because('ticket_conflict', 'That ticket_ref already exists with different details.', 409);
        }

        return $existing;
    }
}
