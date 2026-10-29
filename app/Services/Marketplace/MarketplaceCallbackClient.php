<?php

namespace App\Services\Marketplace;

use App\Jobs\SendMarketplaceCallback;
use App\Models\HostDebt;
use App\Models\TicketDispute;
use App\Models\TicketPayment;
use App\Models\TicketRefund;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells InfoDot "this ticket was paid / refunded" (POST billing.marketplace.callback_url).
 *
 * Same signing scheme as every other signed call: HMAC-SHA256 over "{timestamp}.{raw body}" with the callback
 * secret, sent as "sha256=<hex>" in X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp and this
 * platform in X-InfoDot-Platform. The signature is made at SEND time, so a retry hours later carries a fresh
 * timestamp. The receiver is expected to de-duplicate on (event, ticket_ref), and for ticket.refunded also on
 * refund_key (a ticket can have several partial refunds); the same is sent as Idempotency-Key.
 *
 * Nothing here ever throws into a payment path: a callback that cannot be queued is logged, and InfoDot can
 * always reconcile with GET /api/seminar-tickets/{ticket_ref}.
 */
class MarketplaceCallbackClient
{
    public const DONE = 'done';

    public const RETRY = 'retry';

    public const GIVE_UP = 'give_up';

    public function __construct(private MarketplaceConfig $config) {}

    public function ticketPaid(TicketPayment $ticket): void
    {
        $this->queue([
            'event' => 'ticket.paid',
            'ticket_ref' => $ticket->ticket_ref,
            'reference' => $ticket->paystack_reference,
            'amount_cents' => $ticket->amount_cents,
            'platform_fee_cents' => $ticket->platform_fee_cents,
            'currency' => $ticket->currency,
            'paid_at' => $ticket->paid_at?->toIso8601String(),
        ]);
    }

    /** One callback per processed refund; amount_cents stays the ORIGINAL ticket amount. */
    public function ticketRefunded(TicketPayment $ticket, TicketRefund $refund): void
    {
        $this->queue([
            'event' => 'ticket.refunded',
            'ticket_ref' => $ticket->ticket_ref,
            'reference' => $ticket->paystack_reference,
            'amount_cents' => $ticket->amount_cents,
            'currency' => $ticket->currency,
            'refunded_at' => $refund->processed_at?->toIso8601String(),
            'refund_amount_cents' => $refund->amount_cents,
            'refunded_total_cents' => $ticket->refunded_cents,
            'refund_key' => $refund->refund_key,
        ]);
    }

    public function ticketDisputed(TicketPayment $ticket, TicketDispute $dispute): void
    {
        $this->queue([
            'event' => 'ticket.disputed',
            'ticket_ref' => $ticket->ticket_ref,
            'reference' => $ticket->paystack_reference,
            'amount_cents' => $ticket->amount_cents,
            'currency' => $ticket->currency,
            'opened_at' => $dispute->opened_at?->toIso8601String(),
        ]);
    }

    public function ticketDisputeResolved(TicketPayment $ticket, TicketDispute $dispute): void
    {
        $this->queue([
            'event' => 'ticket.dispute_resolved',
            'ticket_ref' => $ticket->ticket_ref,
            'reference' => $ticket->paystack_reference,
            'amount_cents' => $ticket->amount_cents,
            'currency' => $ticket->currency,
            'outcome' => $dispute->resolution,
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
        ]);
    }

    /**
     * Tell InfoDot about a host debt: host_debt.opened (a lost chargeback), host_debt.cleared (fully recovered),
     * host_debt.reopened (a refund gave recovery back to a cleared debt) or host_debt.written_off (a moderator gave up
     * the rest). ticket_ref is the ticket whose chargeback made the debt, and is unique per debt.
     */
    public function hostDebt(string $event, HostDebt $debt): void
    {
        $debt->loadMissing(['ticket', 'payoutAccount']);

        $payload = [
            'event' => $event,
            'ticket_ref' => $debt->ticket->ticket_ref,
            'debt_id' => $debt->id,
            'payout_account_id' => $debt->payout_account_id,
            'host_ref' => $debt->payoutAccount->host_ref,
            'currency' => $debt->currency,
            'amount_cents' => $debt->amount_cents,
            'recovered_cents' => $debt->recovered_cents,
            'remaining_cents' => $debt->remainingCents(),
        ];

        $payload += match ($event) {
            HostDebts::OPENED => ['opened_at' => $debt->created_at?->toIso8601String()],
            HostDebts::CLEARED => ['cleared_at' => $debt->cleared_at?->toIso8601String()],
            HostDebts::REOPENED => ['reopened_at' => now()->toIso8601String()],
            default => [
                'written_off_at' => $debt->written_off_at?->toIso8601String(),
                'written_off_cents' => max(0, $debt->amount_cents - $debt->recovered_cents),
                'written_off_by_ref' => $debt->written_off_by_ref,
                'note' => $debt->note,
            ],
        };

        $this->queue($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function queue(array $payload): void
    {
        try {
            if (! $this->config->callbackConfigured()) {
                Log::error('Marketplace callback not sent: callback url (https) or secret is not configured.', ['event' => $payload['event'], 'ticket_ref' => $payload['ticket_ref']]);

                return;
            }

            app(Dispatcher::class)->dispatch(new SendMarketplaceCallback($payload));
        } catch (Throwable $e) {
            Log::error('Could not queue the marketplace callback.', ['event' => $payload['event'], 'ticket_ref' => $payload['ticket_ref'], 'error' => class_basename($e)]);
        }
    }

    /**
     * "{event}:{ticket_ref}", plus what tells apart events that can repeat for one ticket: a partial refund's
     * refund_key, a dispute's opened_at or resolved_at, a host debt's opened_at, cleared_at, reopened_at or written_off_at.
     *
     * @param  array<string, mixed>  $payload
     */
    private function idempotencyKey(array $payload): string
    {
        $parts = [$payload['event'] ?? '', $payload['ticket_ref'] ?? ''];
        $extra = $payload['refund_key'] ?? $payload['cleared_at'] ?? $payload['reopened_at'] ?? $payload['written_off_at'] ?? $payload['opened_at'] ?? $payload['resolved_at'] ?? null;

        return implode(':', $extra === null ? $parts : [...$parts, $extra]);
    }

    /**
     * POST one event now. Returns what the queued job should do next; never throws.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(array $payload): string
    {
        if (! $this->config->callbackConfigured()) {
            Log::error('Marketplace callback abandoned: callback url (https) or secret is not configured.', ['event' => $payload['event'] ?? null]);

            return self::GIVE_UP;
        }

        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = $this->config->callbackTimeout();

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withoutRedirecting()
                ->withHeaders([
                    'X-InfoDot-Platform' => (string) config('billing.notify.platform', 'billing'),
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->config->callbackSecret()),
                    'Idempotency-Key' => $this->idempotencyKey($payload),
                ])
                ->withBody($body, 'application/json')
                ->post((string) $this->config->callbackUrl());
        } catch (ConnectionException) {
            Log::warning('Marketplace callback receiver unreachable; will retry.', ['event' => $payload['event'] ?? null]);

            return self::RETRY;
        } catch (Throwable $e) {
            Log::warning('Marketplace callback failed unexpectedly; will retry.', ['event' => $payload['event'] ?? null, 'error' => class_basename($e)]);

            return self::RETRY;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return self::DONE;
        }

        // Server trouble, throttling, or a receiver that is mis-set (bad secret, wrong path) and may be fixed
        // within hours: money events are worth retrying. Other 4xx cannot be fixed by sending the same thing again.
        if ($status >= 500 || in_array($status, [401, 403, 404, 408, 425, 429], true)) {
            Log::warning('Marketplace callback refused or unavailable; will retry.', ['event' => $payload['event'] ?? null, 'status' => $status]);

            return self::RETRY;
        }

        Log::error('Marketplace callback rejected by the receiver.', ['event' => $payload['event'] ?? null, 'ticket_ref' => $payload['ticket_ref'] ?? null, 'status' => $status]);

        return self::GIVE_UP;
    }
}
