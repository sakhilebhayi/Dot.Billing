<?php

namespace App\Jobs;

use App\Services\Marketplace\MarketplaceCallbackClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Delivers one signed ticket event (ticket.paid / ticket.refunded) to InfoDot off the request path. Retries
 * with backoff while InfoDot is down or refusing, then fails into failed_jobs so an operator sees it. The
 * receiver de-duplicates on (event, ticket_ref), so a retry after a lost response is harmless. Needs a queue
 * worker (php artisan queue:work); with QUEUE_CONNECTION=sync it runs inline.
 */
class SendMarketplaceCallback implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 8;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public array $payload) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 7200, 14400, 43200];
    }

    public function handle(MarketplaceCallbackClient $client): void
    {
        $outcome = $client->deliver($this->payload);

        if ($outcome !== MarketplaceCallbackClient::RETRY) {
            return;
        }

        if ($this->job && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)] ?? 60);

            return;
        }

        if ($this->job) {
            Log::error('Marketplace callback abandoned after repeated failures.', ['event' => $this->payload['event'] ?? null, 'ticket_ref' => $this->payload['ticket_ref'] ?? null]);
            $this->fail(new RuntimeException('Marketplace callback abandoned after repeated failures.'));
        }
    }
}
