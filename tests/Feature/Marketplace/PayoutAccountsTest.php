<?php

namespace Tests\Feature\Marketplace;

use App\Models\BillingAuditEvent;
use App\Models\PaymentEvent;
use App\Models\PayoutAccount;
use App\Support\Redacted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

class PayoutAccountsTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configureMarketplace();
        $this->fakeMarketplaceHttp();
        $this->captureLogs();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'host_ref' => 'host-1001',
            'business_name' => 'Sunrise Seminars (Pty) Ltd',
            'settlement_bank' => '051001',
            'account_number' => $this->accountNumber,
        ], $overrides);
    }

    private function assertAccountNumberNowhere(string $where = ''): void
    {
        $this->assertStringNotContainsString($this->accountNumber, $this->databaseDump(), 'database '.$where);
        $this->assertStringNotContainsString($this->accountNumber, implode("\n", $this->logged), 'logs '.$where);
    }

    public function test_it_creates_a_subaccount_with_the_platform_percentage_and_stores_only_the_code_and_last4(): void
    {
        $response = $this->signed('POST', '/api/payout-accounts', $this->body());

        $account = PayoutAccount::sole();
        $response->assertCreated()->assertExactJson(['id' => $account->id, 'status' => 'active', 'last4' => '0123', 'bank_code' => '051001']);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.paystack.co/subaccount'
            && $r->data() === [
                'business_name' => 'Sunrise Seminars (Pty) Ltd',
                'settlement_bank' => '051001',
                'account_number' => $this->accountNumber,
                'percentage_charge' => 10,
            ]);
        Http::assertSentCount(1);

        $this->assertSame(['host-1001', 'ACCT_test000000001', '051001', '0123', 'Sunrise Seminars (Pty) Ltd', 'active'], [
            $account->host_ref, $account->paystack_subaccount_code, $account->bank_code, $account->account_last4, $account->business_name, $account->status,
        ]);
    }

    public function test_the_subaccount_code_is_encrypted_at_rest_and_never_returned(): void
    {
        $response = $this->signed('POST', '/api/payout-accounts', $this->body());

        $raw = DB::table('payout_accounts')->value('paystack_subaccount_code');
        $this->assertStringNotContainsString('ACCT_test000000001', $raw);
        $this->assertSame('ACCT_test000000001', PayoutAccount::sole()->paystack_subaccount_code);
        $this->assertStringNotContainsString('ACCT_', $response->getContent());
        $this->assertArrayNotHasKey('paystack_subaccount_code', PayoutAccount::sole()->toArray());
    }

    public function test_the_percentage_follows_the_configured_platform_percent(): void
    {
        config(['billing.marketplace.platform_percent' => 15]);

        $this->signed('POST', '/api/payout-accounts', $this->body())->assertCreated();

        Http::assertSent(fn (Request $r) => $r->data()['percentage_charge'] === 15);
    }

    public function test_the_account_number_is_never_persisted_returned_or_logged_and_never_in_a_payment_event_or_audit_row(): void
    {
        $response = $this->signed('POST', '/api/payout-accounts', $this->body());
        $response->assertCreated();

        $this->assertStringNotContainsString($this->accountNumber, $response->getContent());
        $this->assertAccountNumberNowhere('after create');
        $this->assertSame(0, PaymentEvent::withoutGlobalScope('team')->count());
        $this->assertStringNotContainsString($this->accountNumber, json_encode(BillingAuditEvent::withoutGlobalScope('team')->get()->toArray()));

        // Paystack echoes the full account number back in its answer; none of it may leak either.
        $this->signed('POST', '/api/payout-accounts', $this->body(['account_number' => '9876543210987']))->assertOk();
        $this->assertStringNotContainsString('9876543210987', $this->databaseDump());
        $this->assertStringNotContainsString('9876543210987', implode("\n", $this->logged));
        $this->assertSame('0987', PayoutAccount::sole()->account_last4);
    }

    public function test_posting_the_same_host_again_updates_the_subaccount_instead_of_creating_a_second_one(): void
    {
        $first = $this->signed('POST', '/api/payout-accounts', $this->body())->assertCreated()->json('id');
        $this->fakeMarketplaceHttp();

        $second = $this->signed('POST', '/api/payout-accounts', $this->body([
            'business_name' => 'Sunrise Seminars Renamed', 'settlement_bank' => '632005', 'account_number' => '5555566666777',
        ]));

        $second->assertOk()->assertExactJson(['id' => $first, 'status' => 'active', 'last4' => '6777', 'bank_code' => '632005']);
        $this->assertSame(1, PayoutAccount::count());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://api.paystack.co/subaccount/ACCT_test000000001'
            && $r->data()['settlement_bank'] === '632005' && $r->data()['business_name'] === 'Sunrise Seminars Renamed'
            && $r->data()['account_number'] === '5555566666777' && $r->data()['percentage_charge'] === 10);

        $account = PayoutAccount::sole();
        $this->assertSame(['Sunrise Seminars Renamed', '632005', '6777'], [$account->business_name, $account->bank_code, $account->account_last4]);
        $this->assertStringNotContainsString('5555566666777', $this->databaseDump());
    }

    public function test_different_hosts_get_different_accounts_and_host_ref_is_unique_in_the_database(): void
    {
        $a = $this->signed('POST', '/api/payout-accounts', $this->body(['host_ref' => 'host-a']))->json('id');
        $b = $this->signed('POST', '/api/payout-accounts', $this->body(['host_ref' => 'host-b']))->json('id');

        $this->assertNotSame($a, $b);
        $this->assertSame(2, PayoutAccount::count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makePayoutAccount(['host_ref' => 'host-a']);
    }

    public function test_a_disabled_account_stays_disabled_when_reposted(): void
    {
        $this->signed('POST', '/api/payout-accounts', $this->body())->assertCreated();
        PayoutAccount::sole()->forceFill(['status' => 'disabled'])->save();

        $this->signed('POST', '/api/payout-accounts', $this->body())->assertOk()->assertJsonPath('status', 'disabled');
        $this->assertSame('disabled', PayoutAccount::sole()->status);
    }

    public function test_it_validates_the_input_and_never_echoes_the_account_number_in_errors(): void
    {
        foreach ([
            ['host_ref' => str_repeat('h', 65)],
            ['host_ref' => 'has space'],
            ['host_ref' => ''],
            ['business_name' => ''],
            ['settlement_bank' => ''],
            ['account_number' => '12ab'],
            ['account_number' => '123'],
            ['account_number' => 1234567890],
        ] as $bad) {
            $response = $this->signed('POST', '/api/payout-accounts', $this->body($bad))->assertUnprocessable();
            $this->assertStringNotContainsString($this->accountNumber, $response->getContent());
        }

        $this->assertSame(0, PayoutAccount::count());
        Http::assertNothingSent();
    }

    public function test_a_paystack_refusal_is_a_422_with_no_row_and_no_account_number_in_the_logs(): void
    {
        $this->fakeMarketplaceHttp(['api.paystack.co/subaccount' => Http::response(['status' => false, 'message' => 'Account number '.$this->accountNumber.' is invalid'], 400)]);

        $response = $this->signed('POST', '/api/payout-accounts', $this->body());

        $response->assertUnprocessable()->assertJsonPath('error', 'payout_account_rejected');
        $this->assertStringNotContainsString($this->accountNumber, $response->getContent());
        $this->assertSame(0, PayoutAccount::count());
        $this->assertSame(0, BillingAuditEvent::withoutGlobalScope('team')->count());
        $this->assertAccountNumberNowhere('after refusal');
    }

    public function test_paystack_being_down_is_a_503_with_no_row(): void
    {
        $this->fakeMarketplaceHttp(['api.paystack.co/subaccount' => Http::response('', 502)]);

        $this->signed('POST', '/api/payout-accounts', $this->body())->assertStatus(503)->assertJsonPath('error', 'payment_provider_unavailable');
        $this->assertSame(0, PayoutAccount::count());
        $this->assertAccountNumberNowhere('after outage');
    }

    public function test_an_unexpected_exception_does_not_put_the_account_number_in_the_logged_trace_or_the_response(): void
    {
        $this->fakeMarketplaceHttp(['api.paystack.co/subaccount' => fn () => throw new \RuntimeException('boom')]);

        $response = $this->signed('POST', '/api/payout-accounts', $this->body());

        $response->assertStatus(500);
        $this->assertStringNotContainsString($this->accountNumber, $response->getContent());
        $this->assertAccountNumberNowhere('after a crash');
        $this->assertNotEmpty(array_filter($this->logged, fn ($l) => str_contains($l, 'boom')), 'the crash was reported, without the number');
    }

    public function test_an_unreachable_paystack_is_a_503_and_logs_no_account_number(): void
    {
        $this->fakeMarketplaceHttp(['api.paystack.co/subaccount' => fn () => throw new ConnectionException('cURL error 28 for '.$this->accountNumber)]);

        $this->signed('POST', '/api/payout-accounts', $this->body())->assertStatus(503);
        $this->assertAccountNumberNowhere('after a connection failure');
    }

    public function test_create_and_update_are_audited_without_a_team_and_without_the_number(): void
    {
        $this->signed('POST', '/api/payout-accounts', $this->body())->assertCreated();
        $this->fakeMarketplaceHttp();
        $this->signed('POST', '/api/payout-accounts', $this->body(['account_number' => '5555566666777']))->assertOk();

        $events = BillingAuditEvent::withoutGlobalScope('team')->orderBy('id')->get();
        $this->assertSame(['payout_account.created', 'payout_account.updated'], $events->pluck('action')->all());
        $this->assertNull($events[0]->team_id);
        $this->assertSame('payout_account', $events[0]->subject_type);
        $this->assertSame(PayoutAccount::sole()->id, $events[0]->subject_id);
        $this->assertSame(['host_ref' => 'host-1001', 'bank_code' => '051001', 'last4' => '0123', 'status' => 'active', 'platform_percent' => 10], $events[0]->meta);
        $this->assertSame('6777', $events[1]->meta['last4']);
    }

    public function test_the_redacted_wrapper_hides_its_value_everywhere_but_reveal(): void
    {
        $secret = new Redacted('1234567890123');

        $this->assertSame('[redacted]', (string) $secret);
        $this->assertSame('"[redacted]"', json_encode($secret));
        $this->assertStringNotContainsString('1234567890123', print_r($secret, true));
        $this->assertStringNotContainsString('1234567890123', var_export($secret, true));
        $this->assertStringNotContainsString('1234567890123', serialize($secret));
        $this->assertStringNotContainsString('1234567890123', json_encode(['n' => $secret]));
        $this->assertSame('0123', $secret->last4());
        $this->assertSame('1234567890123', $secret->reveal());
    }

    public function test_it_is_a_503_when_paystack_is_not_configured(): void
    {
        $this->unconfigurePaystack();

        $this->signed('POST', '/api/payout-accounts', $this->body())->assertStatus(503);
        Http::assertNothingSent();
        $this->assertSame(0, PayoutAccount::count());
    }
}
