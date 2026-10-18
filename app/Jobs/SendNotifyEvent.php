<?php

namespace App\Jobs;

use App\Services\Notify\NotifyClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one event to Dot.Notify off the request path. Retries with backoff while Notify is down,
 * gives up quietly on a refusal, and never throws: a reminder that cannot be sent must not break anything.
 * Needs a queue worker (php artisan queue:work); with QUEUE_CONNECTION=sync it runs inline.
 */
class SendNotifyEvent implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public array $payload) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 7200];
    }

    public function handle(NotifyClient $client): void
    {
        try {
            $outcome = $client->deliver($this->payload);
        } catch (Throwable $e) {
            $outcome = NotifyClient::RETRY;
        }

        if ($outcome !== NotifyClient::RETRY) {
            return;
        }

        if ($this->job && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)] ?? 60);

            return;
        }

        if ($this->job) {
            Log::warning('Dot.Notify event abandoned after repeated failures.', ['event' => $this->payload['event'] ?? null]);
        }
    }
}
