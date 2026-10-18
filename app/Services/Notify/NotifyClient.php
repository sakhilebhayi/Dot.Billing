<?php

namespace App\Services\Notify;

use App\Jobs\SendNotifyEvent;
use App\Models\BillingInvoice;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells Dot.Notify "this happened to this company, tell this person" (POST /api/ingest/events).
 *
 * Built to be harmless when things go wrong: the event is queued (never sent inside the user's request),
 * the HTTP call has a 5 second timeout, nothing here ever throws into the caller, and with no
 * NOTIFY_INGEST_URL or NOTIFY_INGEST_SECRET it does nothing at all.
 *
 * Signing is Notify's scheme: HMAC-SHA256 over "{timestamp}.{raw body}" with this platform's secret, sent
 * as "sha256=<hex>" in X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp. The signature is
 * made at SEND time, so a retry hours later carries a fresh timestamp instead of a stale one.
 *
 * Idempotency: the key is event + invoice id + the date of the attempt, so Notify collapses duplicates
 * within a day but a reminder on a later day is a new event.
 */
class NotifyClient
{
    public const DONE = 'done';

    public const RETRY = 'retry';

    public const GIVE_UP = 'give_up';

    public function enabled(): bool
    {
        return filled(config('billing.notify.url')) && filled(config('billing.notify.secret'));
    }

    /**
     * Queue an "invoice.overdue" reminder to the team owner. Never throws.
     */
    public function invoiceOverdue(BillingInvoice $invoice, ?CarbonInterface $on = null): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            $payload = $this->invoiceOverduePayload($invoice, $on ?? now());

            if ($payload === null) {
                Log::info('Overdue reminder skipped: team is not linked to InfoDot or has no owner email.', ['invoice_id' => $invoice->id]);

                return;
            }

            app(Dispatcher::class)->dispatch(new SendNotifyEvent($payload));
        } catch (Throwable $e) {
            Log::warning('Could not queue the overdue reminder.', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>|null null when the company cannot be addressed
     */
    public function invoiceOverduePayload(BillingInvoice $invoice, CarbonInterface $on): ?array
    {
        $team = $invoice->team()->first();
        $uuid = $team?->infodot_team_uuid;
        $email = $team?->owner?->email;

        if (! filled($uuid) || ! filled($email)) {
            return null;
        }

        return [
            'platform' => (string) config('billing.notify.platform', 'billing'),
            'company_uuid' => $uuid,
            'event' => 'invoice.overdue',
            'recipient' => ['email' => $email],
            'data' => [
                'team_name' => $team->name,
                'invoice_number' => $invoice->invoice_number,
                'total' => $invoice->totalMoney()->format(),
                'balance' => $invoice->balanceMoney()->format(),
                'currency' => $invoice->currency,
                'due_date' => $invoice->due_date?->toDateString(),
                'days_overdue' => $invoice->due_date ? max(0, (int) floor($invoice->due_date->diffInDays($on, false))) : 0,
                'invoice_url' => route('invoices.show', $invoice),
            ],
            'idempotency_key' => 'invoice.overdue:'.$invoice->id.':'.$on->toDateString(),
        ];
    }

    /**
     * POST one event now. Returns what the caller (the queued job) should do next; never throws.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(array $payload): string
    {
        if (! $this->enabled()) {
            return self::GIVE_UP;
        }

        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('billing.notify.timeout', 5);

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('billing.notify.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('billing.notify.url'));
        } catch (ConnectionException $e) {
            Log::warning('Dot.Notify unreachable; will retry.', ['error' => class_basename($e)]);

            return self::RETRY;
        } catch (Throwable $e) {
            Log::warning('Dot.Notify call failed unexpectedly; will retry.', ['error' => $e->getMessage()]);

            return self::RETRY;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return self::DONE;
        }

        if ($status === 429 || $status >= 500) {
            Log::warning('Dot.Notify unavailable; will retry.', ['status' => $status]);

            return self::RETRY;
        }

        // 401/403: our secret is wrong; 404: the company is unknown; 422: the payload is bad. Retrying cannot fix these.
        Log::error('Dot.Notify refused the event.', ['status' => $status, 'event' => $payload['event'] ?? null]);

        return self::GIVE_UP;
    }
}
