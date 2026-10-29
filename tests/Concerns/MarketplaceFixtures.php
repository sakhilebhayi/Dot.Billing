<?php

namespace Tests\Concerns;

use App\Models\PayoutAccount;
use App\Models\TicketPayment;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Seminar marketplace test helpers. Every secret here is a made-up test value, and Http is always faked
 * (Http::fake is installed before anything can reach Paystack), so no test ever makes a real call.
 *
 * @mixin TestCase
 */
trait MarketplaceFixtures
{
    use PaystackFixtures;

    protected string $infodotSecret = 'infodot-entitlement-secret-for-tests-0001';

    protected string $callbackSecret = 'infodot-callback-secret-for-tests-0002';

    protected string $accountNumber = '1234567890123';

    /** @var list<string> */
    protected array $logged = [];

    protected function configureMarketplace(array $overrides = []): void
    {
        $this->configurePaystack();
        config([
            'billing.marketplace' => array_merge([
                'enabled' => true,
                'platform_percent' => 10,
                'currencies' => ['ZAR'],
                'min_amount_cents' => 500,
                'max_amount_cents' => 5000000,
                'callback_url' => 'https://infodot.example/billing/callbacks',
                'callback_secret' => $this->callbackSecret,
                'callback_timeout' => 5,
                'return_hosts' => ['infodot.example'],
                'banks_cache_seconds' => 21600,
            ], $overrides),
            'billing.entitlements.secrets.infodot' => $this->infodotSecret,
            'billing.entitlements.secrets.files' => 'files-entitlement-secret-for-tests-0003',
        ]);
    }

    protected function captureLogs(): void
    {
        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $line = $e->level.' '.$e->message.' '.json_encode($e->context);

            if (($e->context['exception'] ?? null) instanceof \Throwable) {
                $line .= ' '.$e->context['exception']->getMessage().' '.$e->context['exception']->getTraceAsString();
            }

            $this->logged[] = $line;
        });
    }

    /**
     * A call exactly as InfoDot would make it: HMAC-SHA256 over "{timestamp}.{raw body}".
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function signed(string $method, string $uri, ?array $body = null, string $platform = 'infodot', ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $raw = $body === null ? '' : json_encode($body);
        $timestamp ??= now()->timestamp;
        $secret ??= $this->infodotSecret;

        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_PLATFORM' => $platform,
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret),
        ], $raw);
    }

    /** @param  array<string, mixed>  $stubs  Paystack URL pattern => response, replacing the default for that pattern */
    protected function fakeMarketplaceHttp(array $stubs = []): void
    {
        $defaults = [
            'api.paystack.co/bank*' => Http::response(['status' => true, 'data' => [
                ['name' => 'Standard Bank', 'slug' => 'standard-bank', 'code' => '051001', 'currency' => 'ZAR', 'active' => true],
                ['name' => 'Absa Bank', 'slug' => 'absa', 'code' => '632005', 'currency' => 'ZAR', 'active' => true],
                ['name' => 'Closed Bank', 'slug' => 'closed', 'code' => '999999', 'currency' => 'ZAR', 'active' => false],
            ]]),
            'api.paystack.co/subaccount/*' => Http::response(['status' => true, 'data' => ['subaccount_code' => 'ACCT_test000000001', 'account_number' => $this->accountNumber]]),
            'api.paystack.co/subaccount' => Http::response(['status' => true, 'data' => ['subaccount_code' => 'ACCT_test000000001', 'account_number' => $this->accountNumber]]),
            'api.paystack.co/transaction/initialize' => fn (Request $r) => Http::response(['status' => true, 'data' => [
                'authorization_url' => 'https://checkout.paystack.com/tkt'.substr(sha1($r->data()['reference']), 0, 8),
                'access_code' => 'tkt', 'reference' => $r->data()['reference'],
            ]]),
            'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['status' => 'pending']]),
        ];

        $this->fakeHttp($stubs + $defaults);
    }

    protected function makePayoutAccount(array $attributes = []): PayoutAccount
    {
        return PayoutAccount::create(array_merge([
            'host_ref' => 'host-'.Str::lower(Str::random(8)),
            'paystack_subaccount_code' => 'ACCT_test000000001',
            'bank_code' => '051001',
            'account_last4' => '0123',
            'business_name' => 'Test Host (Pty) Ltd',
            'status' => PayoutAccount::ACTIVE,
        ], $attributes));
    }

    protected function makeTicket(array $attributes = [], ?PayoutAccount $account = null): TicketPayment
    {
        $account ??= $this->makePayoutAccount();

        return TicketPayment::create(array_merge([
            'ticket_ref' => 'tkt-'.Str::lower(Str::random(10)),
            'payout_account_id' => $account->id,
            'buyer_email' => 'attendee@example.test',
            'amount_cents' => 20000,
            'platform_fee_cents' => 2000,
            'currency' => 'ZAR',
            'description' => 'Seminar ticket',
            'paystack_reference' => 'dbt-'.Str::lower((string) Str::ulid()),
            'authorization_url' => 'https://checkout.paystack.com/'.Str::lower(Str::random(8)),
            'status' => TicketPayment::PENDING,
        ], $attributes));
    }

    protected function makePaidTicket(array $attributes = []): TicketPayment
    {
        return $this->makeTicket(array_merge(['status' => TicketPayment::PAID, 'paid_at' => now(), 'paystack_transaction_id' => '5550001'], $attributes));
    }

    /**
     * What Paystack's verify endpoint returns for this ticket, with overrides to `data`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function ticketVerifyBody(TicketPayment $ticket, array $data = []): array
    {
        return [
            'status' => true,
            'message' => 'Verification successful',
            'data' => array_merge([
                'id' => 5550001,
                'status' => 'success',
                'reference' => $ticket->paystack_reference,
                'amount' => $ticket->amount_cents,
                'currency' => $ticket->currency,
                'channel' => 'card',
                'paid_at' => '2026-11-05T09:01:00.000Z',
                'gateway_response' => 'Successful',
            ], $data),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function fakeTicketVerify(TicketPayment $ticket, array $data = [], int $http = 200): void
    {
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => Http::response($this->ticketVerifyBody($ticket, $data), $http)]);
    }

    /**
     * A charge.success webhook body for this ticket. The body deliberately repeats the ticket's own facts: the
     * ticket path must not trust it, it verifies with Paystack.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function ticketChargeSuccess(TicketPayment $ticket, array $data = []): array
    {
        return ['event' => 'charge.success', 'data' => $this->ticketVerifyBody($ticket, $data)['data']];
    }

    /** @return array<string, mixed> */
    protected function ticketRefundProcessed(TicketPayment $ticket, array $data = []): array
    {
        return ['event' => 'refund.processed', 'data' => array_merge([
            'status' => 'processed',
            'transaction_reference' => $ticket->paystack_reference,
            'refund_reference' => 'rf_'.$ticket->id,
            'amount' => $ticket->amount_cents,
            'currency' => $ticket->currency,
        ], $data)];
    }

    /**
     * A charge.dispute.create / charge.dispute.resolve webhook body for a transaction reference.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function disputeEvent(string $event, string $reference, array $data = []): array
    {
        return ['event' => $event, 'data' => array_merge([
            'id' => 358950,
            'status' => $event === 'charge.dispute.resolve' ? 'resolved' : 'awaiting-merchant-feedback',
            'resolution' => null,
            'created_at' => '2026-11-06T08:00:00.000Z',
            'resolved_at' => $event === 'charge.dispute.resolve' ? '2026-11-20T10:00:00.000Z' : null,
            'transaction' => ['id' => 5550001, 'reference' => $reference, 'amount' => 20000, 'currency' => 'ZAR'],
        ], $data)];
    }

    /** Every row of every table as one string, to prove a value is stored nowhere. */
    protected function databaseDump(): string
    {
        $dump = '';

        foreach (DB::select("select name from sqlite_master where type = 'table'") as $table) {
            $dump .= json_encode(DB::table($table->name)->get()->all());
        }

        return $dump;
    }
}
