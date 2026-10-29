<?php

namespace Tests\Feature\Marketplace;

use App\Models\BillingAuditEvent;
use App\Models\PayoutAccount;
use App\Models\TicketPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

class SeminarTicketCreateTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    private PayoutAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configureMarketplace();
        $this->fakeMarketplaceHttp();
        $this->account = $this->makePayoutAccount(['host_ref' => 'host-1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'ticket_ref' => 'tkt-0001',
            'payout_account_id' => $this->account->id,
            'buyer_email' => 'Attendee@Example.test',
            'amount_cents' => 20000,
            'currency' => 'ZAR',
            'description' => 'Pricing masterclass, 14 Nov',
        ], $overrides);
    }

    // ---- the money split --------------------------------------------------------------------

    public function test_the_worked_example_r200_ticket_has_a_r20_platform_fee(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();

        $ticket = TicketPayment::sole();
        $this->assertSame([20000, 2000, 'ZAR'], [$ticket->amount_cents, $ticket->platform_fee_cents, $ticket->currency]);
    }

    /** @return array<string, array{0: int, 1: int, 2: int}> amount, percent, expected fee */
    public static function feeCases(): array
    {
        return [
            '10% of R200.00' => [20000, 10, 2000],
            '10% of R10.05 is 100.5 and rounds up' => [1005, 10, 101],
            '10% of R10.04 is 100.4 and rounds down' => [1004, 10, 100],
            '10% of R9.99 is 99.9' => [999, 10, 100],
            '10% of R10.15 is 101.5 and rounds up' => [1015, 10, 102],
            '10% of the minimum R5.00' => [500, 10, 50],
            '10% of the maximum R50,000.00' => [5000000, 10, 500000],
            '15% of 10.03 is 150.45' => [1003, 15, 150],
            '15% of 10.10 is 151.5 and rounds up' => [1010, 15, 152],
            '12% of 8.33 is 99.96' => [833, 12, 100],
            '50% cap' => [999, 50, 500],
            '1% of R5.00' => [500, 1, 5],
            '0% means no platform fee' => [20000, 0, 0],
        ];
    }

    #[DataProvider('feeCases')]
    public function test_the_platform_fee_is_amount_times_percent_rounded_half_up(int $amount, int $percent, int $fee): void
    {
        config(['billing.marketplace.platform_percent' => $percent]);

        $this->signed('POST', '/api/seminar-tickets', $this->body(['amount_cents' => $amount]))->assertCreated();

        $this->assertSame($fee, TicketPayment::sole()->platform_fee_cents);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize') && $r->data()['transaction_charge'] === $fee && $r->data()['amount'] === $amount);
    }

    public function test_the_percent_is_ten_unless_configured_otherwise(): void
    {
        $this->assertSame(10, (int) (require base_path('config/billing.php'))['marketplace']['platform_percent']);
    }

    public function test_the_paystack_payload_splits_to_the_hosts_subaccount_with_a_flat_charge_and_the_host_bearing_the_fee(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();

        $ticket = TicketPayment::sole();

        Http::assertSent(function (Request $r) use ($ticket) {
            $data = $r->data();

            return $r->method() === 'POST' && $r->url() === 'https://api.paystack.co/transaction/initialize'
                && $r->hasHeader('Authorization', 'Bearer '.$this->paystackSecret)
                && $data['subaccount'] === 'ACCT_test000000001'
                && $data['bearer'] === 'subaccount'
                && $data['transaction_charge'] === 2000
                && is_int($data['transaction_charge'])
                && $data['amount'] === 20000 && $data['currency'] === 'ZAR'
                && $data['email'] === 'attendee@example.test'
                && $data['reference'] === $ticket->paystack_reference
                && ! array_key_exists('callback_url', $data)
                && ! array_key_exists('percentage_charge', $data)
                && json_decode($data['metadata'], true) === ['kind' => 'seminar_ticket', 'ticket_ref' => 'tkt-0001', 'description' => 'Pricing masterclass, 14 Nov'];
        });
        Http::assertSentCount(1);
    }

    public function test_the_response_has_the_reference_checkout_address_and_pending_status(): void
    {
        $response = $this->signed('POST', '/api/seminar-tickets', $this->body());
        $ticket = TicketPayment::sole();

        $response->assertCreated()->assertExactJson([
            'ticket_ref' => 'tkt-0001',
            'reference' => $ticket->paystack_reference,
            'authorization_url' => $ticket->authorization_url,
            'status' => 'pending',
        ]);
        $this->assertStringStartsWith('https://checkout.paystack.com/', $ticket->authorization_url);
        $this->assertStringStartsWith('dbt-', $ticket->paystack_reference);
        $this->assertSame('pending', $ticket->status);
        $this->assertNull($ticket->paid_at);
    }

    public function test_a_lowercase_currency_is_accepted_and_stored_uppercase(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body(['currency' => 'zar']))->assertCreated();

        $this->assertSame('ZAR', TicketPayment::sole()->currency);
    }

    public function test_creation_is_audited_without_a_team(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();

        $event = BillingAuditEvent::withoutGlobalScope('team')->sole();
        $this->assertSame('ticket.created', $event->action);
        $this->assertNull($event->team_id);
        $this->assertSame('ticket_payment', $event->subject_type);
        $this->assertSame(2000, $event->meta['platform_fee_cents']);
    }

    // ---- validation -------------------------------------------------------------------------

    public function test_the_amount_bounds_are_inclusive_and_configurable(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body(['ticket_ref' => 'min-ok', 'amount_cents' => 500]))->assertCreated();
        $this->signed('POST', '/api/seminar-tickets', $this->body(['ticket_ref' => 'max-ok', 'amount_cents' => 5000000]))->assertCreated();
        $this->signed('POST', '/api/seminar-tickets', $this->body(['ticket_ref' => 'min-bad', 'amount_cents' => 499]))->assertUnprocessable()->assertJsonValidationErrors('amount_cents');
        $this->signed('POST', '/api/seminar-tickets', $this->body(['ticket_ref' => 'max-bad', 'amount_cents' => 5000001]))->assertUnprocessable()->assertJsonValidationErrors('amount_cents');

        config(['billing.marketplace.min_amount_cents' => 1000, 'billing.marketplace.max_amount_cents' => 2000]);
        $this->signed('POST', '/api/seminar-tickets', $this->body(['ticket_ref' => 'cfg-low', 'amount_cents' => 999]))->assertUnprocessable();
        $this->signed('POST', '/api/seminar-tickets', $this->body(['ticket_ref' => 'cfg-high', 'amount_cents' => 2001]))->assertUnprocessable();
        $this->signed('POST', '/api/seminar-tickets', $this->body(['ticket_ref' => 'cfg-ok', 'amount_cents' => 1500]))->assertCreated();
    }

    public function test_the_bounds_default_to_r5_and_r50000(): void
    {
        $config = (require base_path('config/billing.php'))['marketplace'];

        $this->assertSame([500, 5000000, ['ZAR']], [$config['min_amount_cents'], $config['max_amount_cents'], $config['currencies']]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidInput(): array
    {
        return [
            'amount as a string' => [['amount_cents' => '20000'], 'amount_cents'],
            'amount as a float' => [['amount_cents' => 200.5], 'amount_cents'],
            'negative amount' => [['amount_cents' => -1], 'amount_cents'],
            'missing amount' => [['amount_cents' => null], 'amount_cents'],
            'currency not allowed' => [['currency' => 'USD'], 'currency'],
            'currency missing' => [['currency' => null], 'currency'],
            'bad email' => [['buyer_email' => 'not-an-email'], 'buyer_email'],
            'description too long' => [['description' => str_repeat('d', 141)], 'description'],
            'description missing' => [['description' => null], 'description'],
            'ticket_ref too long' => [['ticket_ref' => str_repeat('t', 65)], 'ticket_ref'],
            'ticket_ref with a slash' => [['ticket_ref' => 'a/b'], 'ticket_ref'],
            'ticket_ref missing' => [['ticket_ref' => null], 'ticket_ref'],
            'payout account as a string' => [['payout_account_id' => 'abc'], 'payout_account_id'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_a_422_that_calls_nothing(array $overrides, string $field): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);

        Http::assertNothingSent();
        $this->assertSame(0, TicketPayment::count());
    }

    public function test_the_description_may_be_exactly_140_characters(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body(['description' => str_repeat('d', 140)]))->assertCreated();
    }

    public function test_an_unknown_or_disabled_payout_account_is_refused_without_calling_paystack(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body(['payout_account_id' => 99999]))->assertUnprocessable()->assertJsonPath('error', 'payout_account_unavailable');

        $this->account->forceFill(['status' => 'disabled'])->save();
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertUnprocessable()->assertJsonPath('error', 'payout_account_unavailable');

        Http::assertNothingSent();
        $this->assertSame(0, TicketPayment::count());
    }

    public function test_it_is_a_503_when_paystack_is_not_configured(): void
    {
        $this->unconfigurePaystack();

        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertStatus(503);
        $this->assertSame(0, TicketPayment::count());
    }

    // ---- return_url --------------------------------------------------------------------------

    public function test_an_allowed_return_url_becomes_paystacks_callback_url(): void
    {
        $url = 'https://infodot.example/seminars/42/thanks?ref=tkt-0001';

        $this->signed('POST', '/api/seminar-tickets', $this->body(['return_url' => $url]))->assertCreated();

        $this->assertSame($url, TicketPayment::sole()->return_url);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize') && $r->data()['callback_url'] === $url);
    }

    public function test_the_return_url_host_match_ignores_case(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body(['return_url' => 'https://INFODOT.example/x']))->assertCreated();
    }

    /** @return array<string, array{0: string}> */
    public static function badReturnUrls(): array
    {
        return [
            'unlisted host' => ['https://evil.example/thanks'],
            'subdomain of a listed host' => ['https://x.infodot.example/thanks'],
            'listed host as a suffix of another' => ['https://notinfodot.example/thanks'],
            'listed host in the path' => ['https://evil.example/infodot.example'],
            'plain http' => ['http://infodot.example/thanks'],
            'no scheme' => ['//infodot.example/thanks'],
            'javascript scheme' => ['javascript:alert(1)'],
            'credentials trick' => ['https://infodot.example@evil.example/thanks'],
            'credentials on the listed host' => ['https://user:pw@infodot.example/thanks'],
            'backslash trick' => ['https://evil.example\\@infodot.example/thanks'],
            'whitespace' => ['https://infodot.example/a b'],
            'too long' => ['https://infodot.example/'.'a'],
        ];
    }

    #[DataProvider('badReturnUrls')]
    public function test_a_return_url_outside_the_allowed_hosts_is_a_422_and_nothing_is_called(string $url): void
    {
        if ($url === 'https://infodot.example/a') {
            $url = 'https://infodot.example/'.str_repeat('a', 300);
        }

        $this->signed('POST', '/api/seminar-tickets', $this->body(['return_url' => $url]))->assertUnprocessable()->assertJsonValidationErrors('return_url');

        Http::assertNothingSent();
        $this->assertSame(0, TicketPayment::count());
    }

    public function test_with_no_return_hosts_configured_every_return_url_is_rejected(): void
    {
        config(['billing.marketplace.return_hosts' => []]);

        $this->signed('POST', '/api/seminar-tickets', $this->body(['return_url' => 'https://infodot.example/thanks']))->assertUnprocessable()->assertJsonValidationErrors('return_url');
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();
    }

    public function test_return_hosts_default_to_empty(): void
    {
        $this->assertSame([], (require base_path('config/billing.php'))['marketplace']['return_hosts']);
    }

    public function test_reaching_the_return_url_never_marks_the_ticket_paid(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body(['return_url' => 'https://infodot.example/thanks']))->assertCreated();

        // The attendee's browser lands on InfoDot's page; nothing on this server is called, so nothing is paid.
        $this->assertSame('pending', TicketPayment::sole()->status);
        $this->signed('GET', '/api/seminar-tickets/tkt-0001')->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('paid_at', null);
    }

    // ---- idempotency and conflicts ------------------------------------------------------------

    public function test_the_same_request_again_returns_the_same_result_without_a_second_paystack_call(): void
    {
        $first = $this->signed('POST', '/api/seminar-tickets', $this->body(['return_url' => 'https://infodot.example/thanks']))->assertCreated();
        $second = $this->signed('POST', '/api/seminar-tickets', $this->body(['return_url' => 'https://infodot.example/thanks']));

        $second->assertOk();
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, TicketPayment::count());
        Http::assertSentCount(1);
    }

    public function test_an_identical_repeat_is_matched_after_normalising_case_in_the_email_and_currency(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();
        $this->signed('POST', '/api/seminar-tickets', $this->body(['buyer_email' => 'ATTENDEE@example.TEST', 'currency' => 'zar']))->assertOk();

        Http::assertSentCount(1);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function changedFields(): array
    {
        return [
            'amount' => [['amount_cents' => 20001]],
            'email' => [['buyer_email' => 'someone.else@example.test']],
            'description' => [['description' => 'Something else']],
            'return_url added' => [['return_url' => 'https://infodot.example/thanks']],
        ];
    }

    #[DataProvider('changedFields')]
    public function test_the_same_ticket_ref_with_different_fields_is_a_409_and_changes_nothing(array $changed): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();
        $before = TicketPayment::sole()->toArray();

        $this->signed('POST', '/api/seminar-tickets', $this->body($changed))->assertStatus(409)->assertJsonPath('error', 'ticket_conflict');

        $this->assertSame($before, TicketPayment::sole()->toArray());
        Http::assertSentCount(1);
    }

    public function test_the_same_ticket_ref_with_a_different_payout_account_is_a_409(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();
        $other = $this->makePayoutAccount();

        $this->signed('POST', '/api/seminar-tickets', $this->body(['payout_account_id' => $other->id]))->assertStatus(409)->assertJsonPath('error', 'ticket_conflict');
        $this->assertSame($this->account->id, TicketPayment::sole()->payout_account_id);
    }

    public function test_a_paid_ticket_ref_is_409_already_paid_even_when_the_fields_differ(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();
        TicketPayment::sole()->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertStatus(409)->assertJsonPath('error', 'already_paid');
        $this->signed('POST', '/api/seminar-tickets', $this->body(['amount_cents' => 99999]))->assertStatus(409)->assertJsonPath('error', 'already_paid');
        Http::assertSentCount(1);
    }

    public function test_a_refunded_ticket_ref_is_also_409_already_paid(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();
        TicketPayment::sole()->forceFill(['status' => 'refunded'])->save();

        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertStatus(409)->assertJsonPath('error', 'already_paid');
    }

    public function test_a_cancelled_ticket_ref_cannot_be_reopened(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();
        $this->signed('POST', '/api/seminar-tickets/tkt-0001/cancel')->assertOk();

        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertStatus(409)->assertJsonPath('error', 'ticket_cancelled');
        Http::assertSentCount(1);
    }

    public function test_when_paystack_cannot_initialise_nothing_is_left_behind_and_a_retry_works(): void
    {
        $this->fakeMarketplaceHttp(['api.paystack.co/transaction/initialize' => Http::response('', 503)]);
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertStatus(503)->assertJsonPath('error', 'payment_provider_unavailable');

        $this->assertSame(0, TicketPayment::count());
        $this->assertSame(0, BillingAuditEvent::withoutGlobalScope('team')->count());

        $this->fakeMarketplaceHttp();
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();
        $this->assertSame(1, TicketPayment::count());
    }

    public function test_a_paystack_refusal_is_a_502_and_leaves_nothing_behind(): void
    {
        $this->fakeMarketplaceHttp(['api.paystack.co/transaction/initialize' => Http::response(['status' => false], 400)]);

        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertStatus(502)->assertJsonPath('error', 'payment_provider_rejected');
        $this->assertSame(0, TicketPayment::count());
    }

    public function test_a_checkout_address_that_is_not_paystacks_is_refused(): void
    {
        $this->fakeMarketplaceHttp(['api.paystack.co/transaction/initialize' => fn (Request $r) => Http::response(['status' => true, 'data' => [
            'authorization_url' => 'https://evil.example/pay', 'access_code' => 'x', 'reference' => $r->data()['reference'],
        ]])]);

        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertStatus(503);
        $this->assertSame(0, TicketPayment::count());
    }

    // ---- reconciliation ----------------------------------------------------------------------

    public function test_the_status_endpoint_reports_the_ticket_for_reconciliation(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->body())->assertCreated();

        $this->signed('GET', '/api/seminar-tickets/tkt-0001')->assertOk()->assertExactJson([
            'ticket_ref' => 'tkt-0001', 'status' => 'pending', 'amount_cents' => 20000, 'platform_fee_cents' => 2000, 'recovery_cents' => 0,
            'currency' => 'ZAR', 'refunded_cents' => 0, 'dispute_status' => null, 'paid_at' => null, 'refunded_at' => null,
        ]);

        TicketPayment::sole()->forceFill(['status' => 'paid', 'paid_at' => '2026-11-05 09:01:00'])->save();

        $this->signed('GET', '/api/seminar-tickets/tkt-0001')->assertOk()
            ->assertJsonPath('status', 'paid')->assertJsonPath('paid_at', '2026-11-05T09:01:00+00:00');
    }

    public function test_an_unknown_ticket_is_a_404_for_an_authenticated_caller(): void
    {
        $this->signed('GET', '/api/seminar-tickets/nope')->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->signed('GET', '/api/seminar-tickets/has%20space')->assertNotFound();
    }
}
