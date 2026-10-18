<?php

namespace Tests\Feature\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Services\Analytics\AnalyticsClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class SendAnalyticsSnapshotJobTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private const URL = 'https://analytics.test/api/ingest/snapshots';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:30:00');
        Cache::flush();
        config(['billing.analytics' => ['url' => self::URL, 'secret' => 's3cret-for-job-tests-0001', 'platform' => 'billing', 'timeout' => 5, 'breaker_threshold' => 5, 'breaker_cooldown' => 300]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(): array
    {
        return app(AnalyticsClient::class)->payload($this->makeCompany(), ['open_invoices' => 1], now());
    }

    public function test_it_tries_three_times_with_a_growing_backoff(): void
    {
        $job = new SendAnalyticsSnapshot($this->payload());

        $this->assertSame(3, $job->tries);
        $backoff = $job->backoff();
        $this->assertCount(2, $backoff);
        $this->assertGreaterThan($backoff[0], $backoff[1]);
    }

    public function test_it_never_throws_whatever_the_client_does(): void
    {
        Http::fake(fn () => throw new \RuntimeException('unexpected'));

        (new SendAnalyticsSnapshot($this->payload()))->handle(app(AnalyticsClient::class));

        $this->assertTrue(true, 'no exception escaped');
    }

    public function test_it_never_throws_when_the_client_itself_blows_up(): void
    {
        $client = \Mockery::mock(AnalyticsClient::class);
        $client->shouldReceive('deliver')->andThrow(new \Error('boom'));

        (new SendAnalyticsSnapshot($this->payload()))->handle($client);

        $this->assertTrue(true);
    }

    public function test_a_retryable_failure_releases_the_job_until_the_last_attempt(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $job = new SendAnalyticsSnapshot($this->payload());
        $fake = \Mockery::mock(Job::class);
        $fake->shouldReceive('attempts')->andReturn(1, 2, 3);
        $fake->shouldReceive('release')->twice();
        $fake->shouldReceive('isReleased', 'isDeleted', 'isDeletedOrReleased', 'hasFailed')->andReturn(false);
        $job->setJob($fake);

        $client = app(AnalyticsClient::class);
        $job->handle($client);
        $job->handle($client);
        $job->handle($client); // third attempt: abandoned quietly, not released again

        $this->assertTrue(true);
    }

    public function test_a_give_up_outcome_does_not_release(): void
    {
        Http::fake(['*' => Http::response('no', 401)]);
        $job = new SendAnalyticsSnapshot($this->payload());
        $fake = \Mockery::mock(Job::class);
        $fake->shouldReceive('attempts')->andReturn(1);
        $fake->shouldNotReceive('release');
        $job->setJob($fake);

        $job->handle(app(AnalyticsClient::class));

        $this->assertTrue(true);
    }
}
