<?php

namespace App\Services\Marketplace;

use App\Jobs\SendMarketplaceCallback;
use App\Models\TicketPayment;
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
 * timestamp. The receiver is expected to de-duplicate on (event, ticket_ref); the same pair is also sent as
 * Idempotency-Key.
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

    public function ticketRefunded(TicketPayment $ticket): void
    {
        $this->queue([
            'event' => 'ticket.refunded',
            'ticket_ref' => $ticket->ticket_ref,
            'reference' => $ticket->paystack_reference,
            'amount_cents' => $ticket->amount_cents,
            'currency' => $ticket->currency,
            'refunded_at' => $ticket->refunded_at?->toIso8601String(),
        ]);
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
                    'Idempotency-Key' => ($payload['event'] ?? '').':'.($payload['ticket_ref'] ?? ''),
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
