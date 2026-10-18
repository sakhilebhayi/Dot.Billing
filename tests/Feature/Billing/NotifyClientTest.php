<?php

namespace Tests\Feature\Billing;

use App\Console\Commands\ScanBillingAlerts;
use App\Jobs\SendNotifyEvent;
use App\Models\BillingInvoice;
use App\Models\DunningCase;
use App\Models\User;
use App\Services\Notify\NotifyClient;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class NotifyClientTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private const URL = 'https://notify.test/api/ingest/events';

    private const SECRET = 'notify-ingest-secret-for-tests-0001';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        Notification::fake();
        config(['billing.notify' => ['url' => self::URL, 'secret' => self::SECRET, 'timeout' => 5, 'platform' => 'billing']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{invoice: BillingInvoice, owner: User, uuid: string} */
    private function overdue(array $extra = []): array
    {
        $owner = $this->makeOwner();
        $uuid = $this->linkToInfoDot($owner->currentTeam);
        $invoice = $this->makeInvoice($owner->currentTeam, $extra + [
            'invoice_number' => 'NOTIFY-INV-DISTINCT-8841', 'total_cents' => 123450, 'currency' => 'USD', 'due_date' => now()->subDays(3),
        ]);

        return compact('invoice', 'owner', 'uuid');
    }

    public function test_an_overdue_scan_posts_a_signed_invoice_overdue_event_to_notify(): void
    {
        Http::fake([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);
        ['invoice' => $invoice, 'owner' => $owner, 'uuid' => $uuid] = $this->overdue();

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($invoice, $owner, $uuid) {
            $body = $request->body();
            $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);
            $json = json_decode($body, true);

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && ($request->header('X-InfoDot-Signature')[0] ?? '') === $expected
                && abs((int) $timestamp - now()->timestamp) <= 5
                && $json['platform'] === 'billing'
                && $json['company_uuid'] === $uuid
                && $json['event'] === 'invoice.overdue'
                && $json['recipient']['email'] === $owner->email
                && $json['idempotency_key'] === 'invoice.overdue:'.$invoice->id.':2026-11-05'
                && $json['data']['invoice_number'] === 'NOTIFY-INV-DISTINCT-8841'
                && $json['data']['balance'] === 'USD 1,234.50'
                && $json['data']['days_overdue'] === 3
                && ! str_contains($body, self::SECRET);
        });
    }

    public function test_the_request_uses_a_five_second_timeout(): void
    {
        $seen = null;
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response([], 202);
        });
        $this->overdue();

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertSame(5, (int) ($seen['timeout'] ?? 0));
        $this->assertSame(5, (int) ($seen['connect_timeout'] ?? 0));
    }

    public function test_the_event_is_queued_not_sent_inside_the_users_request(): void
    {
        Queue::fake();
        Http::fake();
        $this->overdue();

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        Queue::assertPushed(SendNotifyEvent::class, 1);
        Http::assertNothingSent();
    }

    public function test_it_is_disabled_when_the_url_or_the_secret_is_blank(): void
    {
        Http::fake();

        foreach ([['url' => ''], ['secret' => ''], ['url' => null, 'secret' => null]] as $blank) {
            config(['billing.notify' => array_merge(config('billing.notify'), $blank)]);
            $this->assertFalse(app(NotifyClient::class)->enabled());
            ['invoice' => $invoice] = $this->overdue();

            app(NotifyClient::class)->invoiceOverdue($invoice);
        }

        Http::assertNothingSent();
        Queue::fake();
        app(NotifyClient::class)->invoiceOverdue($invoice);
        Queue::assertNothingPushed();
    }

    public function test_when_notify_is_down_the_scan_still_opens_the_case_and_nothing_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        ['invoice' => $invoice] = $this->overdue();

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertSame(1, DunningCase::where('invoice_id', $invoice->id)->count());
    }

    public function test_a_server_error_from_notify_does_not_break_the_user_action_either(): void
    {
        Http::fake([self::URL => Http::response('boom', 503)]);
        ['invoice' => $invoice] = $this->overdue();

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertSame(1, DunningCase::where('invoice_id', $invoice->id)->count());
        Http::assertSentCount(1);
    }

    public function test_a_transient_failure_asks_the_queue_to_retry_and_a_permanent_one_does_not(): void
    {
        ['invoice' => $invoice] = $this->overdue();
        $payload = app(NotifyClient::class)->invoiceOverduePayload($invoice, now());
        $client = app(NotifyClient::class);

        Http::fake(['*' => Http::sequence()->push('x', 503)->push('x', 429)->push('x', 422)->push([], 200)->pushFailedConnection()]);

        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::GIVE_UP, $client->deliver($payload), '422: the payload is wrong, retrying cannot fix it');
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload), 'connection failure');
    }

    public function test_a_retry_is_signed_afresh_with_a_new_timestamp(): void
    {
        Http::fake([self::URL => Http::response([], 202)]);
        ['invoice' => $invoice] = $this->overdue();
        $payload = app(NotifyClient::class)->invoiceOverduePayload($invoice, now());

        app(NotifyClient::class)->deliver($payload);
        Carbon::setTestNow(now()->addMinutes(10));
        app(NotifyClient::class)->deliver($payload);

        $stamps = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('X-InfoDot-Timestamp')[0])->all();
        $this->assertCount(2, array_unique($stamps), 'a delayed retry must not carry a stale timestamp');
    }

    public function test_the_idempotency_key_is_event_invoice_and_attempt_date(): void
    {
        ['invoice' => $invoice] = $this->overdue();
        $client = app(NotifyClient::class);

        $today = $client->invoiceOverduePayload($invoice, Carbon::parse('2026-11-05 23:59:00'));
        $tomorrow = $client->invoiceOverduePayload($invoice, Carbon::parse('2026-11-06 00:01:00'));

        $this->assertSame('invoice.overdue:'.$invoice->id.':2026-11-05', $today['idempotency_key']);
        $this->assertSame('invoice.overdue:'.$invoice->id.':2026-11-06', $tomorrow['idempotency_key']);
    }

    public function test_a_failure_to_even_queue_the_event_never_reaches_the_caller(): void
    {
        ['invoice' => $invoice] = $this->overdue();
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue backend down')));

        app(NotifyClient::class)->invoiceOverdue($invoice);

        $this->assertTrue(true, 'no exception escaped');
    }

    public function test_a_team_not_linked_to_infodot_cannot_be_addressed_and_is_skipped(): void
    {
        Http::fake();
        $owner = $this->makeOwner(); // no infodot uuid
        $invoice = $this->makeInvoice($owner->currentTeam, ['due_date' => now()->subDays(3)]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(1, DunningCase::where('invoice_id', $invoice->id)->count());
    }

    public function test_the_job_never_throws_whatever_the_client_does(): void
    {
        Http::fake(fn () => throw new \RuntimeException('unexpected'));
        ['invoice' => $invoice] = $this->overdue();
        $payload = app(NotifyClient::class)->invoiceOverduePayload($invoice, now());

        (new SendNotifyEvent($payload))->handle(app(NotifyClient::class));

        $this->assertTrue(true);
    }

    public function test_the_scanner_command_is_still_registered(): void
    {
        $this->assertTrue(class_exists(ScanBillingAlerts::class));
    }
}
