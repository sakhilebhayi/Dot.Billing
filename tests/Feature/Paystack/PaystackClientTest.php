<?php

namespace Tests\Feature\Paystack;

use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\PaymentAttempt;
use App\Services\Payments\Paystack\PaystackClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PaystackFixtures;
use Tests\TestCase;

class PaystackClientTest extends TestCase
{
    use BillingFixtures;
    use PaystackFixtures;
    use RefreshDatabase;

    private PaymentAttempt $attempt;

    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePaystack();
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 123456, 'invoice_number' => 'INV-CLIENT-TEST-1']);
        $this->attempt = $this->makeAttempt($invoice, ['reference' => 'dbi-client-ref-001']);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->level.' '.$e->message.' '.json_encode($e->context);
        });
    }

    private function client(): PaystackClient
    {
        return app(PaystackClient::class);
    }

    public function test_initialize_sends_exactly_the_documented_payload_with_bearer_auth(): void
    {
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => Http::response([
            'status' => true, 'message' => 'Authorization URL created',
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'abc123', 'reference' => 'dbi-client-ref-001'],
        ])]);

        $result = $this->client()->initializeTransaction($this->attempt, 'payer@example.test');

        $this->assertSame('https://checkout.paystack.com/abc123', $result->authorizationUrl);
        $this->assertSame('dbi-client-ref-001', $result->reference);

        Http::assertSent(function (Request $request) {
            $body = $request->data();
            $metadata = json_decode($body['metadata'], true);
            $invoice = $this->attempt->invoice()->withoutGlobalScope('team')->first();

            return $request->method() === 'POST'
                && $request->url() === 'https://api.paystack.co/transaction/initialize'
                && $request->hasHeader('Authorization', 'Bearer '.$this->paystackSecret)
                && array_keys($body) === ['email', 'amount', 'currency', 'reference', 'callback_url', 'metadata']
                && $body['email'] === 'payer@example.test'
                && $body['amount'] === 123456
                && $body['currency'] === 'ZAR'
                && $body['reference'] === 'dbi-client-ref-001'
                && $body['callback_url'] === route('paystack.callback', ['attempt' => $this->attempt->uuid])
                && $metadata === [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => 'INV-CLIENT-TEST-1',
                    'team_id' => $invoice->team_id,
                    'attempt' => $this->attempt->uuid,
                ];
        });
    }

    public function test_initialize_logs_no_secret_access_code_or_checkout_url(): void
    {
        $this->fakeHttp(['*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/SECRETCODE9', 'access_code' => 'SECRETCODE9', 'reference' => 'dbi-client-ref-001'],
        ])]);

        $this->client()->initializeTransaction($this->attempt, 'payer@example.test');

        $log = implode("\n", $this->logged);
        $this->assertStringNotContainsString($this->paystackSecret, $log);
        $this->assertStringNotContainsString('SECRETCODE9', $log);
    }

    public function test_failures_never_log_the_secret_or_the_response_body(): void
    {
        $this->fakeHttp(['*' => Http::response(['message' => 'Invalid key '.$this->paystackSecret.' BODYLEAK77'], 401)]);

        try {
            $this->client()->verifyTransaction('dbi-client-ref-001');
            $this->fail('Expected a gateway exception.');
        } catch (PaymentGatewayException $e) {
            $this->assertStringNotContainsString($this->paystackSecret, $e->getMessage());
            $this->assertStringNotContainsString('BODYLEAK77', $e->getMessage());
        }

        $log = implode("\n", $this->logged);
        $this->assertNotEmpty($this->logged);
        $this->assertStringNotContainsString($this->paystackSecret, $log);
        $this->assertStringNotContainsString('BODYLEAK77', $log);
    }

    public function test_verify_returns_a_typed_transaction(): void
    {
        $this->fakeVerify($this->attempt, ['id' => 4099260516]);

        $tx = $this->client()->verifyTransaction($this->attempt->reference);

        $this->assertSame('4099260516', $tx->id);
        $this->assertSame($this->attempt->reference, $tx->reference);
        $this->assertSame('success', $tx->status);
        $this->assertSame(123456, $tx->amountCents);
        $this->assertSame('ZAR', $tx->currency);
        $this->assertTrue($tx->isSuccessful());

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://api.paystack.co/transaction/verify/dbi-client-ref-001'
            && $r->hasHeader('Authorization', 'Bearer '.$this->paystackSecret));
    }

    public function test_verify_reports_a_non_success_status_without_pretending_it_succeeded(): void
    {
        foreach (['failed', 'abandoned', 'ongoing', 'reversed'] as $status) {
            $this->fakeVerify($this->attempt, ['status' => $status]);

            $tx = $this->client()->verifyTransaction($this->attempt->reference);

            $this->assertSame($status, $tx->status);
            $this->assertFalse($tx->isSuccessful(), $status);
        }
    }

    public function test_http_errors_become_friendly_gateway_exceptions(): void
    {
        foreach ([500, 502, 401, 404, 302] as $status) {
            $this->fakeHttp(['*' => Http::response('', $status)]);

            try {
                $this->client()->verifyTransaction('dbi-client-ref-001');
                $this->fail("HTTP {$status} should fail.");
            } catch (PaymentGatewayException $e) {
                $this->assertNotSame('', $e->getMessage());
                $this->assertStringNotContainsString((string) $status, $e->getMessage(), 'No raw status code in the message shown to people.');
            }
        }
    }

    public function test_a_timeout_becomes_a_friendly_gateway_exception(): void
    {
        $this->fakeHttp(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->client()->initializeTransaction($this->attempt, 'payer@example.test');
            $this->fail('Expected a gateway exception.');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('unavailable', $e->reason);
            $this->assertStringNotContainsString('cURL', $e->getMessage());
        }
    }

    public function test_an_unreadable_or_incomplete_response_is_rejected(): void
    {
        $this->fakeHttp(['*' => Http::response('<html>gateway page</html>', 200)]);
        $this->assertGatewayReason('invalid_response', fn () => $this->client()->verifyTransaction('x'));

        $this->fakeHttp(['*' => Http::response(['status' => true, 'data' => ['reference' => 'x']], 200)]);
        $this->assertGatewayReason('invalid_response', fn () => $this->client()->verifyTransaction('x'));

        $this->fakeHttp(['*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://evil.example.test/pay', 'access_code' => 'a', 'reference' => $this->attempt->reference]], 200)]);
        $this->assertGatewayReason('invalid_response', fn () => $this->client()->initializeTransaction($this->attempt, 'payer@example.test'));

        $this->fakeHttp(['*' => Http::response(['status' => false, 'message' => 'Duplicate Transaction Reference'], 400)]);
        $this->assertGatewayReason('rejected', fn () => $this->client()->initializeTransaction($this->attempt, 'payer@example.test'));
    }

    public function test_the_client_refuses_to_run_with_no_key_and_sends_nothing(): void
    {
        $this->unconfigurePaystack();
        $this->fakeHttp();

        $this->assertGatewayReason('not_configured', fn () => $this->client()->verifyTransaction('x'));

        Http::assertNothingSent();
    }

    private function assertGatewayReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected a gateway exception with reason {$reason}.");
        } catch (PaymentGatewayException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
