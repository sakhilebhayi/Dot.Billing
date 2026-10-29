<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\SendMarketplaceCallback;
use App\Services\Marketplace\MarketplaceCallbackClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

class TicketCallbackTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configureMarketplace();
        $this->captureLogs();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function paidPayload(): array
    {
        return [
            'event' => 'ticket.paid', 'ticket_ref' => 'tkt-cb-1', 'reference' => 'dbt-cb-1', 'amount_cents' => 20000,
            'platform_fee_cents' => 2000, 'currency' => 'ZAR', 'paid_at' => '2026-11-05T09:01:00+00:00',
        ];
    }

    private function jobOnAttempt(array $payload, int $attempt): array
    {
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $job = new SendMarketplaceCallback($payload);
        $job->setJob($queueJob);

        return [$job, $queueJob];
    }

    public function test_the_callback_is_signed_with_the_callback_secret_over_timestamp_dot_raw_body(): void
    {
        $this->fakeHttp(['infodot.example/*' => Http::response('', 200)]);

        (new SendMarketplaceCallback($this->paidPayload()))->handle(app(MarketplaceCallbackClient::class));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            $timestamp = $r->header('X-InfoDot-Timestamp')[0];
            $body = $r->body();

            return $r->method() === 'POST'
                && $r->url() === 'https://infodot.example/billing/callbacks'
                && $timestamp === (string) now()->timestamp
                && $r->header('X-InfoDot-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->callbackSecret)
                && $r->header('X-InfoDot-Signature')[0] !== 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->infodotSecret)
                && $r->header('X-InfoDot-Platform')[0] === 'billing'
                && $r->header('Idempotency-Key')[0] === 'ticket.paid:tkt-cb-1'
                && $r->hasHeader('Content-Type', 'application/json')
                && json_decode($body, true) === $this->paidPayload();
        });
    }

    public function test_the_idempotency_key_tells_apart_events_that_can_repeat_for_one_ticket(): void
    {
        $this->fakeHttp(['infodot.example/*' => Http::response('', 200)]);
        $client = app(MarketplaceCallbackClient::class);
        $base = ['ticket_ref' => 'tkt-cb-1', 'reference' => 'dbt-cb-1', 'amount_cents' => 20000, 'currency' => 'ZAR'];

        $client->deliver($base + ['event' => 'ticket.refunded', 'refund_key' => 'r-1', 'refund_amount_cents' => 5000]);
        $client->deliver($base + ['event' => 'ticket.refunded', 'refund_key' => 'r-2', 'refund_amount_cents' => 5000]);
        $client->deliver($base + ['event' => 'ticket.disputed', 'opened_at' => '2026-11-06T08:00:00+00:00']);
        $client->deliver($base + ['event' => 'ticket.dispute_resolved', 'outcome' => 'won', 'resolved_at' => '2026-11-20T10:00:00+00:00']);
        $client->deliver($this->paidPayload());

        $keys = [];
        Http::assertSent(function (Request $r) use (&$keys) {
            $keys[] = $r->header('Idempotency-Key')[0];

            return true;
        });
        $this->assertSame([
            'ticket.refunded:tkt-cb-1:r-1', 'ticket.refunded:tkt-cb-1:r-2',
            'ticket.disputed:tkt-cb-1:2026-11-06T08:00:00+00:00', 'ticket.dispute_resolved:tkt-cb-1:2026-11-20T10:00:00+00:00',
            'ticket.paid:tkt-cb-1',
        ], $keys);
    }

    public function test_a_retry_hours_later_carries_a_fresh_timestamp_and_signature(): void
    {
        $this->fakeHttp(['infodot.example/*' => Http::response('', 200)]);
        $client = app(MarketplaceCallbackClient::class);

        $client->deliver($this->paidPayload());
        Carbon::setTestNow('2026-11-05 11:00:00');
        $client->deliver($this->paidPayload());

        $stamps = [];
        Http::assertSent(function (Request $r) use (&$stamps) {
            $stamps[] = $r->header('X-InfoDot-Timestamp')[0];

            return true;
        });
        $this->assertCount(2, array_unique($stamps));
    }

    public function test_a_paid_ticket_queues_a_callback_job_and_a_refund_queues_another(): void
    {
        Queue::fake();
        $this->fakeHttp();
        $ticket = $this->makeTicket();
        $this->fakeTicketVerify($ticket);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk();
        $this->webhook($this->ticketRefundProcessed($ticket))->assertOk();

        Queue::assertPushed(SendMarketplaceCallback::class, 2);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload['event'] === 'ticket.paid' && $j->payload['ticket_ref'] === $ticket->ticket_ref);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload['event'] === 'ticket.refunded' && $j->payload['ticket_ref'] === $ticket->ticket_ref);
    }

    public function test_with_the_sync_queue_the_signed_callback_goes_out_when_the_ticket_is_paid(): void
    {
        $ticket = $this->makeTicket();
        $this->fakeHttp([
            'api.paystack.co/transaction/verify/*' => Http::response($this->ticketVerifyBody($ticket)),
            'infodot.example/*' => Http::response('', 200),
        ]);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://infodot.example/billing/callbacks'
            && json_decode($r->body(), true)['event'] === 'ticket.paid'
            && json_decode($r->body(), true)['ticket_ref'] === $ticket->ticket_ref);
    }

    public function test_a_callback_that_cannot_be_delivered_never_undoes_the_payment(): void
    {
        $ticket = $this->makeTicket();
        $this->fakeHttp([
            'api.paystack.co/transaction/verify/*' => Http::response($this->ticketVerifyBody($ticket)),
            'infodot.example/*' => Http::response('', 500),
        ]);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk();

        $this->assertSame('paid', $ticket->fresh()->status);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function receiverAnswers(): array
    {
        return [
            '200' => [200, MarketplaceCallbackClient::DONE],
            '204' => [204, MarketplaceCallbackClient::DONE],
            '500' => [500, MarketplaceCallbackClient::RETRY],
            '503' => [503, MarketplaceCallbackClient::RETRY],
            '429' => [429, MarketplaceCallbackClient::RETRY],
            '401 secret not yet fixed' => [401, MarketplaceCallbackClient::RETRY],
            '404 path not yet deployed' => [404, MarketplaceCallbackClient::RETRY],
            '422 payload refused' => [422, MarketplaceCallbackClient::GIVE_UP],
            '400' => [400, MarketplaceCallbackClient::GIVE_UP],
        ];
    }

    #[DataProvider('receiverAnswers')]
    public function test_what_the_receiver_answers_decides_whether_to_retry(int $status, string $expected): void
    {
        $this->fakeHttp(['infodot.example/*' => Http::response('', $status)]);

        $this->assertSame($expected, app(MarketplaceCallbackClient::class)->deliver($this->paidPayload()));
    }

    public function test_an_unreachable_receiver_is_retried(): void
    {
        $this->fakeHttp(['infodot.example/*' => fn () => throw new ConnectionException('down')]);

        $this->assertSame(MarketplaceCallbackClient::RETRY, app(MarketplaceCallbackClient::class)->deliver($this->paidPayload()));
    }

    public function test_the_job_retries_with_backoff_then_gives_up_into_failed_jobs(): void
    {
        $this->fakeHttp(['infodot.example/*' => Http::response('', 500)]);
        $client = app(MarketplaceCallbackClient::class);

        $this->assertSame([60, 300, 900, 3600, 7200, 14400, 43200], (new SendMarketplaceCallback([]))->backoff());

        [$job, $queueJob] = $this->jobOnAttempt($this->paidPayload(), 1);
        $queueJob->shouldReceive('release')->once()->with(60);
        $job->handle($client);

        [$job, $queueJob] = $this->jobOnAttempt($this->paidPayload(), 3);
        $queueJob->shouldReceive('release')->once()->with(900);
        $job->handle($client);

        [$job, $queueJob] = $this->jobOnAttempt($this->paidPayload(), 8);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldReceive('fail')->once();
        $queueJob->shouldReceive('markAsFailed')->zeroOrMoreTimes();
        $queueJob->shouldReceive('isDeleted')->andReturn(false);
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('hasFailed')->andReturn(false);
        $job->handle($client);

        $this->assertNotEmpty(array_filter($this->logged, fn ($l) => str_contains($l, 'abandoned after repeated failures') && str_contains($l, 'tkt-cb-1')));
    }

    public function test_a_delivered_callback_is_not_released(): void
    {
        $this->fakeHttp(['infodot.example/*' => Http::response('', 200)]);

        [$job, $queueJob] = $this->jobOnAttempt($this->paidPayload(), 1);
        $queueJob->shouldNotReceive('release');
        $job->handle(app(MarketplaceCallbackClient::class));

        $this->addToAssertionCount(1);
    }

    public function test_nothing_is_queued_or_sent_when_the_callback_address_or_secret_is_missing_or_not_https(): void
    {
        Queue::fake();
        $this->fakeHttp();

        foreach ([['callback_url' => null], ['callback_secret' => ''], ['callback_url' => 'http://infodot.example/cb']] as $bad) {
            config(['billing.marketplace' => array_merge(config('billing.marketplace'), $bad)]);
            $ticket = $this->makeTicket();
            $this->fakeTicketVerify($ticket);

            $this->webhook($this->ticketChargeSuccess($ticket))->assertOk();

            $this->assertSame('paid', $ticket->fresh()->status, 'the payment is still recorded');
        }

        Queue::assertNothingPushed();
        $this->assertNotEmpty(array_filter($this->logged, fn ($l) => str_contains($l, 'Marketplace callback not sent')));
        $this->assertSame(MarketplaceCallbackClient::GIVE_UP, app(MarketplaceCallbackClient::class)->deliver($this->paidPayload()));
    }

    public function test_the_callback_secret_is_never_logged_or_stored_in_the_job(): void
    {
        $this->fakeHttp(['infodot.example/*' => Http::response('', 500)]);

        app(MarketplaceCallbackClient::class)->deliver($this->paidPayload());

        $this->assertStringNotContainsString($this->callbackSecret, implode("\n", $this->logged));
        $this->assertStringNotContainsString($this->callbackSecret, json_encode((new SendMarketplaceCallback($this->paidPayload()))->payload));
    }
}
