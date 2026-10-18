<?php

namespace Tests\Concerns;

use App\Models\BillingInvoice;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Paystack test helpers. Every key here is a made-up test value; no test ever makes a real HTTP call
 * (Http::fake is always installed before anything can reach the client).
 *
 * @mixin TestCase
 */
trait PaystackFixtures
{
    /** Split in two so secret scanners do not mistake a made-up test value for a real key. */
    protected string $paystackSecret = 'sk_'.'test_0000000000000000000000000000000000fake1';

    protected function configurePaystack(?string $secret = null, bool $allowLive = false): void
    {
        config([
            'paystack.secret_key' => $secret ?? $this->paystackSecret,
            'paystack.public_key' => 'pk_'.'test_0000000000000000000000000000000000fake2',
            'paystack.base_url' => 'https://api.paystack.co',
            'paystack.allow_live' => $allowLive,
        ]);
    }

    protected function liveKey(): string
    {
        return 'sk_'.'live_0000000000000000000000000000000000fake3';
    }

    protected function unconfigurePaystack(): void
    {
        config(['paystack.secret_key' => null, 'paystack.public_key' => null, 'paystack.allow_live' => false]);
    }

    protected function makeAttempt(BillingInvoice $invoice, array $attributes = [], ?User $by = null): PaymentAttempt
    {
        return PaymentAttempt::withoutGlobalScope('team')->create(array_merge([
            'team_id' => $invoice->team_id,
            'invoice_id' => $invoice->id,
            'provider' => 'paystack',
            'reference' => 'dbi'.$invoice->id.'-'.Str::lower(Str::random(20)),
            'amount_cents' => $invoice->balanceMoney()->cents,
            'currency' => $invoice->currency,
            'status' => 'initiated',
            'authorization_url' => 'https://checkout.paystack.com/'.Str::lower(Str::random(12)),
            'initiated_by' => $by?->id,
            'expires_at' => now()->addMinutes(30),
        ], $attributes));
    }

    /**
     * What Paystack's verify endpoint returns for this attempt, with optional overrides to `data`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function verifyBody(PaymentAttempt $attempt, array $data = []): array
    {
        return [
            'status' => true,
            'message' => 'Verification successful',
            'data' => array_merge([
                'id' => 4099260516,
                'status' => 'success',
                'reference' => $attempt->reference,
                'amount' => $attempt->amount_cents,
                'currency' => $attempt->currency,
                'channel' => 'card',
                'paid_at' => '2026-11-05T09:01:00.000Z',
                'gateway_response' => 'Successful',
                'metadata' => '',
                'authorization' => ['card_type' => 'visa', 'last4' => '4081', 'authorization_code' => 'AUTH_not_for_storage'],
            ], $data),
        ];
    }

    /**
     * Replace the HTTP client with a fresh fake. Http::fake() called twice ADDS stubs and the first match
     * wins, so a test that changes its mind (or loops) must start from a clean factory.
     *
     * @param  callable|array<string, mixed>|null  $stubs
     */
    protected function fakeHttp(callable|array|null $stubs = null): Factory
    {
        $factory = new Factory;
        Http::swap($factory);
        $factory->fake($stubs);

        return $factory;
    }

    protected function fakeVerify(PaymentAttempt $attempt, array $data = [], int $http = 200): void
    {
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => Http::response($this->verifyBody($attempt, $data), $http)]);
    }

    /**
     * A charge.success webhook body for this attempt.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function chargeSuccess(PaymentAttempt $attempt, array $data = [], string $event = 'charge.success'): array
    {
        return ['event' => $event, 'data' => $this->verifyBody($attempt, $data)['data']];
    }

    protected function sign(string $raw, ?string $secret = null): string
    {
        return hash_hmac('sha512', $raw, $secret ?? $this->paystackSecret);
    }

    /**
     * POST the exact raw bytes with a valid signature (unless told otherwise).
     *
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, string>  $headers  server-style headers, e.g. HTTP_X_PAYSTACK_SIGNATURE
     */
    protected function webhook(array|string $payload, ?string $signature = null, array $headers = []): TestResponse
    {
        $raw = is_string($payload) ? $payload : json_encode($payload);

        $server = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? $this->sign($raw),
        ], $headers);

        return $this->call('POST', '/webhooks/paystack', [], [], [], $server, $raw);
    }
}
