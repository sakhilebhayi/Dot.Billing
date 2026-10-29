<?php

namespace Tests\Feature\Marketplace;

use App\Models\BillingAuditEvent;
use App\Models\PaymentEvent;
use App\Models\TicketPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

class MarketplaceAccessTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configureMarketplace();
        $this->fakeMarketplaceHttp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: ?array<string, mixed>}> */
    public static function endpoints(): array
    {
        return [
            'banks' => ['GET', '/api/payout-banks', null],
            'payout account' => ['POST', '/api/payout-accounts', ['host_ref' => 'h1', 'business_name' => 'X', 'settlement_bank' => '051001', 'account_number' => '1234567890']],
            'create ticket' => ['POST', '/api/seminar-tickets', ['ticket_ref' => 't1']],
            'show ticket' => ['GET', '/api/seminar-tickets/t1', null],
            'refund ticket' => ['POST', '/api/seminar-tickets/t1/refund', null],
            'cancel ticket' => ['POST', '/api/seminar-tickets/t1/cancel', null],
            'host agreement' => ['POST', '/api/payout-accounts/1/agreement', ['version' => 'v1', 'accepted_by_ref' => 'u1']],
            'host debts' => ['GET', '/api/payout-accounts/1/debts', null],
            'debt write-off' => ['POST', '/api/host-debts/1/write-off', ['note' => 'x', 'written_off_by_ref' => 'm1']],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_every_endpoint_is_a_404_while_the_marketplace_is_off(string $method, string $uri, ?array $body): void
    {
        config(['billing.marketplace.enabled' => false]);

        // Signed correctly, and unsigned: both are the same 404, nothing is looked up, Paystack is never called.
        $this->signed($method, $uri, $body)->assertNotFound();
        $this->json($method, $uri, $body ?? [])->assertNotFound();
        Http::assertNothingSent();
        $this->assertSame(0, TicketPayment::count());
    }

    public function test_the_marketplace_is_off_by_default(): void
    {
        $this->assertFalse((bool) (require base_path('config/billing.php'))['marketplace']['enabled']);
    }

    #[DataProvider('endpoints')]
    public function test_a_missing_signature_is_a_generic_401(string $method, string $uri, ?array $body): void
    {
        $this->json($method, $uri, $body ?? [])->assertUnauthorized()->assertExactJson(['error' => 'invalid_request']);
        Http::assertNothingSent();
    }

    #[DataProvider('endpoints')]
    public function test_a_bad_stale_or_foreign_signature_is_the_same_generic_401(string $method, string $uri, ?array $body): void
    {
        $cases = [
            'wrong secret' => $this->signed($method, $uri, $body, 'infodot', 'not-the-secret'),
            'stale timestamp' => $this->signed($method, $uri, $body, 'infodot', null, now()->timestamp - 301),
            'future timestamp' => $this->signed($method, $uri, $body, 'infodot', null, now()->timestamp + 301),
            'other platform, its own valid secret' => $this->signed($method, $uri, $body, 'files', 'files-entitlement-secret-for-tests-0003'),
            'other platform claiming to be infodot' => $this->signed($method, $uri, $body, 'infodot', 'files-entitlement-secret-for-tests-0003'),
            'unknown platform' => $this->signed($method, $uri, $body, 'nobody', $this->infodotSecret),
        ];

        foreach ($cases as $label => $response) {
            $response->assertUnauthorized();
            $this->assertSame('{"error":"invalid_request"}', $response->getContent(), $label);
        }

        Http::assertNothingSent();
    }

    public function test_a_blank_infodot_secret_means_infodot_cannot_call(): void
    {
        config(['billing.entitlements.secrets.infodot' => '']);

        $this->signed('GET', '/api/payout-banks', null, 'infodot', '')->assertUnauthorized();
    }

    public function test_a_body_changed_after_signing_is_rejected(): void
    {
        $account = $this->makePayoutAccount();
        $timestamp = now()->timestamp;
        $signed = json_encode(['ticket_ref' => 'tkt-sig-1', 'payout_account_id' => $account->id, 'buyer_email' => 'a@example.test', 'amount_cents' => 20000, 'currency' => 'ZAR', 'description' => 'x']);
        $sent = str_replace('20000', '90000', $signed);

        $this->call('POST', '/api/seminar-tickets', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_PLATFORM' => 'infodot', 'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$signed, $this->infodotSecret),
        ], $sent)->assertUnauthorized();

        $this->assertSame(0, TicketPayment::count());
    }

    public function test_authentication_happens_before_any_lookup_so_a_caller_cannot_probe_for_tickets(): void
    {
        $ticket = $this->makePaidTicket(['ticket_ref' => 'tkt-exists']);

        foreach (['GET /api/seminar-tickets/', 'POST /api/seminar-tickets/'] as $prefix) {
            [$method, $path] = explode(' ', $prefix);
            $suffix = $method === 'POST' ? '/refund' : '';
            $known = $this->signed($method, $path.'tkt-exists'.$suffix, null, 'infodot', 'wrong');
            $unknown = $this->signed($method, $path.'tkt-nope'.$suffix, null, 'infodot', 'wrong');

            $this->assertSame(401, $known->getStatusCode());
            $this->assertSame($known->getContent(), $unknown->getContent());
        }

        $this->assertNull($ticket->fresh()->refund_requested_at);
        Http::assertNothingSent();
    }

    public function test_the_routes_are_throttled_like_the_entitlements_route(): void
    {
        foreach (['api.marketplace.banks', 'api.marketplace.payout-accounts.store', 'api.marketplace.tickets.store', 'api.marketplace.tickets.show', 'api.marketplace.tickets.refund', 'api.marketplace.tickets.cancel'] as $name) {
            $this->assertContains('throttle:120,1', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_an_unsafe_platform_percent_makes_the_marketplace_refuse_with_503_after_authentication(): void
    {
        foreach ([51, -1, '12.5', 'ten', null, 10.5] as $bad) {
            config(['billing.marketplace.platform_percent' => $bad]);

            $this->signed('GET', '/api/payout-banks')->assertStatus(503)->assertJsonPath('error', 'marketplace_misconfigured');
            $this->signed('GET', '/api/payout-banks', null, 'infodot', 'wrong')->assertUnauthorized();
        }

        foreach ([0, 10, '10', 50] as $good) {
            config(['billing.marketplace.platform_percent' => $good]);
            $this->signed('GET', '/api/payout-banks')->assertOk();
        }
    }

    public function test_misconfigured_bounds_or_currencies_also_refuse(): void
    {
        foreach ([['min_amount_cents' => 0], ['min_amount_cents' => 900, 'max_amount_cents' => 800], ['currencies' => []]] as $bad) {
            config(['billing.marketplace' => array_merge(config('billing.marketplace'), $bad)]);
            $this->signed('GET', '/api/payout-banks')->assertStatus(503);
        }
    }

    public function test_nothing_is_recorded_for_rejected_calls(): void
    {
        $this->signed('POST', '/api/seminar-tickets/t1/refund', null, 'infodot', 'wrong');

        $this->assertSame(0, BillingAuditEvent::withoutGlobalScope('team')->count());
        $this->assertSame(0, PaymentEvent::withoutGlobalScope('team')->count());
    }
}
