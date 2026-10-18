<?php

namespace App\Jobs;

use App\Services\Analytics\AnalyticsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one company's daily snapshot to Dot.Analytics off every request path. Three tries with backoff
 * while Analytics is down or its breaker is open, gives up quietly on a refusal, and never throws: a
 * snapshot that cannot be sent must not break anything (tomorrow's run sends tomorrow's numbers).
 * Needs a queue worker (php artisan queue:work); with QUEUE_CONNECTION=sync it runs inline.
 */
class SendAnalyticsSnapshot implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @param  array<string, mixed>  $payload  aggregates only: platform, company uuid, taken_at, period, metrics */
    public function __construct(public array $payload) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 1800];
    }

    public function handle(AnalyticsClient $client): void
    {
        try {
            $outcome = $client->deliver($this->payload);
        } catch (Throwable $e) {
            $outcome = AnalyticsClient::RETRY;
        }

        if ($outcome !== AnalyticsClient::RETRY && $outcome !== AnalyticsClient::BREAKER_OPEN) {
            return;
        }

        $attempts = $this->job ? $this->attempts() : $this->tries;

        if ($this->job && $attempts < $this->tries) {
            $this->release($this->backoff()[min($attempts - 1, count($this->backoff()) - 1)] ?? 300);

            return;
        }

        if ($this->job) {
            Log::warning('Dot.Analytics snapshot abandoned after repeated failures.', ['platform' => $this->payload['platform'] ?? null]);
        }
    }
}
