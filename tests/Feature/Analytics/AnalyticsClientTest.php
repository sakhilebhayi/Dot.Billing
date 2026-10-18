<?php

namespace Tests\Feature\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Models\Team;
use App\Services\Analytics\AnalyticsClient;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class AnalyticsClientTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private const SECRET = 'analytics-ingest-secret-for-tests-0001';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:30:00');
        Cache::flush();
        config(['billing.analytics' => [
            'url' => self::URL, 'secret' => self::SECRET, 'platform' => 'billing', 'timeout' => 5,
            'breaker_threshold' => 3, 'breaker_cooldown' => 300,
        ]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(?Team $team = null): array
    {
        $team ??= $this->makeCompany();

        return app(AnalyticsClient::class)->payload($team, ['open_invoices' => 2, 'overdue_invoices' => 1], now());
    }

    public function test_the_payload_has_exactly_the_contract_shape(): void
    {
        $team = $this->makeCompany();

        $payload = app(AnalyticsClient::class)->payload($team, ['open_invoices' => 2, 'mrr_cents' => 29900, 'currency' => 'ZAR'], now());

        $this->assertSame([
            'platform' => 'billing',
            'company_uuid' => $team->infodot_team_uuid,
            'taken_at' => '2026-11-05T00:00:00Z',
            'period' => 'day',
            'metrics' => ['open_invoices' => 2, 'mrr_cents' => 29900, 'currency' => 'ZAR'],
        ], $payload);
    }

    public function test_taken_at_is_the_start_of_the_utc_day_so_a_replay_the_same_day_is_the_same_snapshot(): void
    {
        $client = app(AnalyticsClient::class);

        $this->assertSame('2026-11-05T00:00:00Z', $client->takenAt(Carbon::parse('2026-11-05 00:00:00', 'UTC'))->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-11-05T00:00:00Z', $client->takenAt(Carbon::parse('2026-11-05 23:59:59', 'UTC'))->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-11-06T00:00:00Z', $client->takenAt(Carbon::parse('2026-11-06 00:00:01', 'UTC'))->format('Y-m-d\TH:i:s\Z'));
        // 01:30 in Johannesburg (UTC+2) on the 6th is still the 5th in UTC.
        $this->assertSame('2026-11-05T00:00:00Z', $client->takenAt(Carbon::parse('2026-11-06 01:30:00', 'Africa/Johannesburg'))->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_it_posts_one_signed_snapshot_and_the_hmac_recomputes(): void
    {
        Http::fake([self::URL => Http::response(['data' => ['replayed' => false]], 201)]);
        $payload = $this->payload();

        $this->assertSame(AnalyticsClient::DONE, app(AnalyticsClient::class)->deliver($payload));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($payload) {
            $body = $request->body();
            $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && ($request->header('X-InfoDot-Signature')[0] ?? '') === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET)
                && (int) $timestamp === now()->timestamp
                && json_decode($body, true) === $payload
                && ! str_contains($body, self::SECRET)
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_the_request_uses_a_five_second_timeout(): void
    {
        $seen = null;
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response([], 201);
        });

        app(AnalyticsClient::class)->deliver($this->payload());

        $this->assertSame(5, (int) ($seen['timeout'] ?? 0));
        $this->assertSame(5, (int) ($seen['connect_timeout'] ?? 0));
    }

    public function test_a_retry_is_signed_afresh_with_a_new_timestamp(): void
    {
        Http::fake([self::URL => Http::response([], 201)]);
        $payload = $this->payload();

        app(AnalyticsClient::class)->deliver($payload);
        Carbon::setTestNow(now()->addMinutes(10));
        app(AnalyticsClient::class)->deliver($payload);

        $stamps = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('X-InfoDot-Timestamp')[0])->all();
        $this->assertCount(2, array_unique($stamps));
    }

    public function test_it_is_disabled_and_sends_nothing_when_the_url_or_the_secret_is_blank(): void
    {
        Http::fake();
        Queue::fake();
        $team = $this->makeCompany();

        foreach ([['url' => ''], ['secret' => ''], ['url' => null, 'secret' => null]] as $blank) {
            config(['billing.analytics' => array_merge(config('billing.analytics'), $blank)]);
            $client = app(AnalyticsClient::class);

            $this->assertFalse($client->enabled());
            $this->assertFalse($client->publish($team, ['open_invoices' => 1], now()));
            $this->assertSame(AnalyticsClient::GIVE_UP, $client->deliver($this->payload($team)));
            config(['billing.analytics' => array_merge(config('billing.analytics'), ['url' => self::URL, 'secret' => self::SECRET])]);
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_publishing_queues_a_job_and_sends_nothing_inline(): void
    {
        Queue::fake();
        Http::fake();
        $team = $this->makeCompany();

        $this->assertTrue(app(AnalyticsClient::class)->publish($team, ['open_invoices' => 1], now()));

        Queue::assertPushed(SendAnalyticsSnapshot::class, 1);
        Http::assertNothingSent();
    }

    public function test_a_team_with_no_company_uuid_cannot_be_published_for(): void
    {
        Queue::fake();
        $team = $this->makeCompany();
        $team->forceFill(['infodot_team_uuid' => null])->save();

        $this->assertFalse(app(AnalyticsClient::class)->publish($team->fresh(), ['open_invoices' => 1], now()));

        Queue::assertNothingPushed();
    }

    public function test_server_errors_rate_limits_and_timeouts_ask_for_a_retry_and_never_throw(): void
    {
        $client = app(AnalyticsClient::class);
        $payload = $this->payload();

        Http::fake(['*' => Http::sequence()
            ->push('boom', 500)->push('slow down', 429)->push('x', 503)]);
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload));
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload));
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload));

        Cache::flush();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload));

        Cache::flush();
        Http::fake(fn () => throw new \RuntimeException('something unexpected'));
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload));
    }

    public function test_a_refusal_is_final_and_does_not_trip_the_breaker(): void
    {
        $client = app(AnalyticsClient::class);
        Http::fake(['*' => Http::sequence()->push('x', 401)->push('x', 404)->push('x', 422)->push('x', 401)->push([], 201)]);

        foreach ([401, 404, 422, 401] as $status) {
            $this->assertSame(AnalyticsClient::GIVE_UP, $client->deliver($this->payload()), (string) $status);
        }

        $this->assertFalse($client->breakerOpen());
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($this->payload()));
    }

    public function test_a_replayed_snapshot_answered_with_200_is_done(): void
    {
        Http::fake([self::URL => Http::response(['data' => ['replayed' => true]], 200)]);

        $this->assertSame(AnalyticsClient::DONE, app(AnalyticsClient::class)->deliver($this->payload()));
    }

    public function test_the_breaker_opens_after_repeated_failures_stops_calling_and_closes_again_after_a_success(): void
    {
        $client = app(AnalyticsClient::class);
        Http::fake(['*' => Http::sequence()
            ->push('down', 500)->push('down', 500)->push('down', 500) // opens the breaker (threshold 3)
            ->push([], 201)                                          // the probe after the cool-down
            ->push('down', 500)]);                                   // one failure afterwards

        foreach (range(1, 3) as $i) {
            $this->assertSame(AnalyticsClient::RETRY, $client->deliver($this->payload()));
        }
        $this->assertTrue($client->breakerOpen());
        Http::assertSentCount(3);

        $this->assertSame(AnalyticsClient::BREAKER_OPEN, $client->deliver($this->payload()));
        Http::assertSentCount(3); // nothing more went out while open

        // After the cool-down one probe is let through; it succeeds and the breaker closes.
        Carbon::setTestNow(now()->addSeconds(301));
        $this->assertFalse($client->breakerOpen());
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($this->payload()));
        $this->assertFalse($client->breakerOpen());

        // A single failure afterwards does not reopen it: the count started again from zero.
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($this->payload()));
        $this->assertFalse($client->breakerOpen());
        Http::assertSentCount(5);
    }

    public function test_a_failed_probe_after_the_cooldown_reopens_the_breaker_straight_away(): void
    {
        $client = app(AnalyticsClient::class);
        Http::fake(['*' => Http::response('down', 500)]);
        foreach (range(1, 3) as $i) {
            $client->deliver($this->payload());
        }

        Carbon::setTestNow(now()->addSeconds(301));
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($this->payload()));

        $this->assertTrue($client->breakerOpen());
        $this->assertSame(AnalyticsClient::BREAKER_OPEN, $client->deliver($this->payload()));
    }

    public function test_a_broken_cache_never_breaks_delivery(): void
    {
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('forget')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('increment')->andThrow(new \RuntimeException('cache down'));
        Http::fake(['*' => Http::response([], 201)]);

        $this->assertSame(AnalyticsClient::DONE, app(AnalyticsClient::class)->deliver($this->payload()));
    }

    public function test_a_failure_to_even_queue_the_snapshot_never_reaches_the_caller(): void
    {
        $team = $this->makeCompany();
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue backend down')));

        $this->assertFalse(app(AnalyticsClient::class)->publish($team, ['open_invoices' => 1], now()));
    }

    public function test_the_raw_body_carries_aggregates_only_never_row_level_text(): void
    {
        Http::fake([self::URL => Http::response([], 201)]);
        $secretish = [
            'team' => 'SENTINEL-TEAM-NAME-'.str_repeat('t', 40),
            'plan' => 'SENTINEL-PLAN-NAME-'.str_repeat('p', 40),
            'invoice' => 'SENTINEL-INVOICE-NO-'.str_repeat('i', 40),
        ];
        $team = $this->makeCompany(['name' => $secretish['team']]);
        $team->owner->forceFill(['name' => 'SENTINEL-OWNER-'.str_repeat('o', 40), 'email' => 'sentinel-owner-'.str_repeat('e', 30).'@example.test'])->save();
        $this->makeSubscription($team, $this->makePlan(['name' => $secretish['plan']]));
        $this->makeInvoice($team, ['invoice_number' => $secretish['invoice'], 'due_date' => now()->subDays(2)]);

        $this->artisan('billing:publish-metrics')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($secretish, $team) {
            $body = $request->body();
            foreach ($secretish as $needle) {
                if (str_contains($body, $needle)) {
                    return false;
                }
            }

            return ! str_contains($body, 'SENTINEL-OWNER-')
                && ! str_contains($body, 'sentinel-owner-')
                && ! str_contains($body, (string) $team->owner->email)
                && array_keys(json_decode($body, true)) === ['platform', 'company_uuid', 'taken_at', 'period', 'metrics'];
        });
    }
}
