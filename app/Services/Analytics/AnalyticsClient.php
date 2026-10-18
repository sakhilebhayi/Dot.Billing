<?php

namespace App\Services\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes one company's daily aggregate snapshot to Dot.Analytics (POST /api/ingest/snapshots).
 *
 * Built so that Analytics being down, slow or misconfigured can never affect this app, in the same way as
 * the Notify client: the snapshot is queued (never sent inside a request), the HTTP call has a 5 second
 * timeout, nothing here throws into the caller, and with no ANALYTICS_INGEST_URL or ANALYTICS_INGEST_SECRET
 * it does nothing at all. On top of that a circuit breaker stops calling an Analytics that keeps failing.
 *
 * Signing is Analytics' scheme: HMAC-SHA256 over "{timestamp}.{raw body}" with the shared secret, sent as
 * "sha256=<hex>" in X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp. The signature is made at
 * SEND time, so a retry hours later carries a fresh timestamp instead of a stale one.
 *
 * Idempotency lives upstream: a snapshot is identified by (company, platform, taken_at, period) and taken_at
 * is always the start of the UTC day, so a retry or a second run on the same day is the same snapshot.
 */
class AnalyticsClient
{
    public const DONE = 'done';

    public const RETRY = 'retry';

    public const GIVE_UP = 'give_up';

    public const BREAKER_OPEN = 'breaker_open';

    public function enabled(): bool
    {
        return filled(config('billing.analytics.url')) && filled(config('billing.analytics.secret'));
    }

    /** The start of the UTC day the snapshot describes. */
    public function takenAt(CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->utc()->startOfDay();
    }

    /**
     * @param  array<string, int|string>  $metrics
     * @return array<string, mixed>
     */
    public function payload(Team $team, array $metrics, CarbonInterface $now): array
    {
        return [
            'platform' => (string) config('billing.analytics.platform', 'billing'),
            'company_uuid' => (string) $team->infodot_team_uuid,
            'taken_at' => $this->takenAt($now)->format('Y-m-d\TH:i:s\Z'),
            'period' => 'day',
            'metrics' => $metrics,
        ];
    }

    /**
     * Queue one company's snapshot. Returns whether a job was queued; never throws.
     *
     * @param  array<string, int|string>  $metrics
     */
    public function publish(Team $team, array $metrics, CarbonInterface $now): bool
    {
        try {
            if (! $this->enabled() || ! filled($team->infodot_team_uuid) || $metrics === []) {
                return false;
            }

            app(Dispatcher::class)->dispatch(new SendAnalyticsSnapshot($this->payload($team, $metrics, $now)));

            return true;
        } catch (Throwable $e) {
            Log::warning('Could not queue the Analytics snapshot.', ['team_id' => $team->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * POST one snapshot now. Returns what the caller (the queued job) should do next; never throws.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(array $payload): string
    {
        if (! $this->enabled()) {
            return self::GIVE_UP;
        }

        if ($this->breakerOpen()) {
            return self::BREAKER_OPEN;
        }

        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('billing.analytics.timeout', 5);

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('billing.analytics.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('billing.analytics.url'));
        } catch (ConnectionException $e) {
            Log::warning('Dot.Analytics unreachable; will retry.', ['error' => class_basename($e)]);
            $this->recordFailure();

            return self::RETRY;
        } catch (Throwable $e) {
            Log::warning('Dot.Analytics call failed unexpectedly; will retry.', ['error' => class_basename($e)]);
            $this->recordFailure();

            return self::RETRY;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $this->recordSuccess();

            return self::DONE;
        }

        if ($status === 429 || $status >= 500) {
            Log::warning('Dot.Analytics unavailable; will retry.', ['status' => $status]);
            $this->recordFailure();

            return self::RETRY;
        }

        // 401: the secret is wrong; 404: unknown company or platform not connected; 422: the snapshot is invalid.
        // Retrying cannot fix any of these, and none of them says Analytics is unhealthy, so the breaker is left alone.
        Log::error('Dot.Analytics refused the snapshot.', ['status' => $status, 'platform' => $payload['platform'] ?? null]);

        return self::GIVE_UP;
    }

    /**
     * Open after `breaker_threshold` failures in a row; while open nothing is sent. After the cool-down one
     * request is let through: success closes the breaker, another failure opens it again straight away.
     */
    public function breakerOpen(): bool
    {
        try {
            $openedAt = Cache::get($this->key('opened_at'));

            return $openedAt !== null && now()->timestamp < (int) $openedAt + (int) config('billing.analytics.breaker_cooldown', 300);
        } catch (Throwable) {
            return false;
        }
    }

    private function recordFailure(): void
    {
        try {
            $failures = (int) Cache::get($this->key('failures'), 0) + 1;
            Cache::put($this->key('failures'), $failures, 86400);

            if ($failures >= (int) config('billing.analytics.breaker_threshold', 5)) {
                Cache::put($this->key('opened_at'), now()->timestamp, 86400);
                Log::warning('Dot.Analytics circuit breaker opened; snapshots pause until the cool-down ends.', ['failures' => $failures]);
            }
        } catch (Throwable) {
            // The breaker is a courtesy to Analytics; losing it must never break delivery.
        }
    }

    private function recordSuccess(): void
    {
        try {
            Cache::forget($this->key('failures'));
            Cache::forget($this->key('opened_at'));
        } catch (Throwable) {
        }
    }

    private function key(string $name): string
    {
        return 'analytics-publish:'.config('billing.analytics.platform', 'billing').':'.$name;
    }
}
