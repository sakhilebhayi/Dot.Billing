<?php

namespace Tests\Feature\Marketplace;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

class PayoutBanksTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        Cache::flush();
        $this->configureMarketplace();
        $this->fakeMarketplaceHttp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_returns_name_and_code_only_sorted_and_without_inactive_banks(): void
    {
        $this->signed('GET', '/api/payout-banks')->assertOk()->assertExactJson([
            ['name' => 'Absa Bank', 'code' => '632005'],
            ['name' => 'Standard Bank', 'code' => '051001'],
        ]);
    }

    public function test_it_asks_paystack_for_south_african_zar_banks_with_the_secret_key(): void
    {
        $this->signed('GET', '/api/payout-banks')->assertOk();

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://api.paystack.co/bank')
                && $query['country'] === 'south africa' && $query['currency'] === 'ZAR'
                && $request->hasHeader('Authorization', 'Bearer '.$this->paystackSecret);
        });
    }

    public function test_the_list_is_cached_for_six_hours(): void
    {
        $this->signed('GET', '/api/payout-banks')->assertOk();
        $this->signed('GET', '/api/payout-banks')->assertOk();
        Http::assertSentCount(1);

        Carbon::setTestNow(now()->addHours(5)->addMinutes(59));
        $this->signed('GET', '/api/payout-banks')->assertOk();
        Http::assertSentCount(1);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->signed('GET', '/api/payout-banks')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_a_failed_lookup_is_a_503_and_is_not_cached(): void
    {
        $this->fakeMarketplaceHttp(['api.paystack.co/bank*' => Http::response(['status' => false], 500)]);
        $this->signed('GET', '/api/payout-banks')->assertStatus(503)->assertJsonPath('error', 'payment_provider_unavailable');

        $this->fakeMarketplaceHttp();
        $this->signed('GET', '/api/payout-banks')->assertOk()->assertJsonCount(2);
    }

    public function test_it_is_a_503_when_paystack_is_not_configured(): void
    {
        $this->unconfigurePaystack();

        $this->signed('GET', '/api/payout-banks')->assertStatus(503);
        Http::assertNothingSent();
    }
}
