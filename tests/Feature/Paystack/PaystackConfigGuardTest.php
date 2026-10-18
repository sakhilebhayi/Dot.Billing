<?php

namespace Tests\Feature\Paystack;

use App\Exceptions\Payments\PaymentGatewayException;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PaystackFixtures;
use Tests\TestCase;

class PaystackConfigGuardTest extends TestCase
{
    use BillingFixtures;
    use PaystackFixtures;
    use RefreshDatabase;

    public function test_with_no_key_payments_are_off_and_the_mode_is_unknown(): void
    {
        $this->unconfigurePaystack();

        $config = app(PaystackConfig::class);

        $this->assertFalse($config->isConfigured());
        $this->assertFalse($config->canTakePayments());
        $this->assertNull($config->mode());
        $this->assertStringContainsString('not set up', (string) $config->notice());
    }

    public function test_a_test_key_is_test_mode_and_can_take_payments(): void
    {
        $this->configurePaystack();

        $config = app(PaystackConfig::class);

        $this->assertTrue($config->canTakePayments());
        $this->assertSame('test', $config->mode());
        $this->assertSame($this->paystackSecret, $config->secretKey());
    }

    public function test_a_live_key_is_refused_without_the_explicit_allow_flag(): void
    {
        $this->configurePaystack($this->liveKey(), allowLive: false);

        $config = app(PaystackConfig::class);

        $this->assertFalse($config->canTakePayments());
        $this->assertTrue($config->isRefused());
        $this->assertStringContainsString('PAYSTACK_ALLOW_LIVE', (string) $config->notice());

        try {
            $config->secretKey();
            $this->fail('The live key must not be handed out.');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('live_key_refused', $e->reason);
            $this->assertStringNotContainsString($this->liveKey(), $e->getMessage());
        }
    }

    public function test_a_live_key_is_never_sent_and_the_refusal_is_logged_without_the_key(): void
    {
        $this->configurePaystack($this->liveKey(), allowLive: false);
        $this->fakeHttp();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->level.' '.$e->message.' '.json_encode($e->context);
        });

        $owner = $this->makeOwner();
        $attempt = $this->makeAttempt($this->makeInvoice($owner->currentTeam));

        try {
            app(PaystackClient::class)->verifyTransaction($attempt->reference);
            $this->fail('Expected a refusal.');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('live_key_refused', $e->reason);
        }

        Http::assertNothingSent();
        $this->assertNotEmpty($logged, 'The refusal must be logged.');
        $this->assertStringNotContainsString($this->liveKey(), implode("\n", $logged));
    }

    public function test_a_live_key_is_accepted_only_when_the_allow_flag_is_also_set(): void
    {
        $this->configurePaystack($this->liveKey(), allowLive: true);

        $config = app(PaystackConfig::class);

        $this->assertTrue($config->canTakePayments());
        $this->assertSame('live', $config->mode());
        $this->assertSame($this->liveKey(), $config->secretKey());
    }

    public function test_a_key_with_an_unknown_prefix_is_refused(): void
    {
        $this->configurePaystack('rk_test_restricted_key_is_not_a_secret_key');

        $this->assertFalse(app(PaystackConfig::class)->canTakePayments());
    }

    public function test_a_non_https_base_url_is_refused(): void
    {
        $this->configurePaystack();
        config(['paystack.base_url' => 'http://api.paystack.co']);
        $this->fakeHttp();

        try {
            app(PaystackClient::class)->verifyTransaction('whatever');
            $this->fail('Expected a refusal.');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('not_configured', $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_the_default_base_url_is_the_paystack_api(): void
    {
        $this->assertSame('https://api.paystack.co', config('paystack.base_url'));
        $this->assertFalse(config('paystack.allow_live'));
        $this->assertSame(10, config('paystack.timeout'));
    }

    public function test_the_test_mode_badge_shows_for_a_test_key_and_not_otherwise(): void
    {
        $owner = $this->makeOwner();

        $this->configurePaystack();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('TEST MODE');

        $this->unconfigurePaystack();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('TEST MODE');

        $this->configurePaystack($this->liveKey(), allowLive: true);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('TEST MODE');
    }
}
