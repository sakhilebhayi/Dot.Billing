<?php

namespace Tests\Feature\Paystack;

use App\Models\BillingAuditEvent;
use App\Models\BillingPayment;
use App\Models\DunningCase;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PaystackFixtures;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use BillingFixtures;
    use PaystackFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configurePaystack();
        $this->fakeHttp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function events()
    {
        return PaymentEvent::withoutGlobalScope('team');
    }

    private function scene(int $total = 100000): array
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => $total]);

        return [$owner, $invoice, $this->makeAttempt($invoice, [], $owner)];
    }

    // ---- signature -------------------------------------------------------------------------

    public function test_a_valid_signature_over_the_raw_body_is_accepted_and_applies_the_payment(): void
    {
        [, $invoice, $attempt] = $this->scene(100000);

        $this->webhook($this->chargeSuccess($attempt))->assertOk();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(100000, $invoice->amount_paid_cents);
        $event = $this->events()->sole();
        $this->assertSame(['paystack', '4099260516', 'applied'], [$event->provider, $event->external_id, $event->status]);
        $this->assertSame('succeeded', $attempt->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_the_signature_is_checked_over_the_exact_bytes_not_a_reserialised_body(): void
    {
        [, $invoice, $attempt] = $this->scene();
        $raw = "{\n  \"event\": \"charge.success\",\n  \"data\": ".json_encode($this->chargeSuccess($attempt)['data'], JSON_PRETTY_PRINT)."\n}";

        $this->webhook($raw)->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_an_invalid_signature_is_a_generic_401_and_changes_nothing(): void
    {
        [, $invoice, $attempt] = $this->scene();
        $body = $this->chargeSuccess($attempt);

        $response = $this->webhook($body, $this->sign(json_encode($body), 'sk_'.'test_some_other_secret_entirely'));

        $response->assertUnauthorized();
        $this->assertStringNotContainsString('signature', strtolower($response->getContent()), 'The 401 must not explain itself.');
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame(0, $this->events()->count());
    }

    public function test_a_tampered_body_fails_verification(): void
    {
        [, $invoice, $attempt] = $this->scene();
        $signed = json_encode($this->chargeSuccess($attempt));
        $signature = $this->sign($signed);
        $tampered = json_decode($signed, true);
        $tampered['data']['amount'] = 1;

        $this->webhook($tampered, $signature)->assertUnauthorized();

        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame(0, $this->events()->count());
    }

    public function test_a_missing_or_empty_signature_is_a_401(): void
    {
        [, $invoice, $attempt] = $this->scene();
        $raw = json_encode($this->chargeSuccess($attempt));

        $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertUnauthorized();
        $this->webhook($raw, '')->assertUnauthorized();
        $this->webhook($raw, '   ')->assertUnauthorized();

        $this->assertSame('open', $invoice->fresh()->status);
    }

    public function test_the_signature_is_compared_case_insensitively_as_hex(): void
    {
        [, $invoice, $attempt] = $this->scene();
        $raw = json_encode($this->chargeSuccess($attempt));

        $this->webhook($raw, strtoupper($this->sign($raw)))->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_with_no_key_configured_every_webhook_is_refused(): void
    {
        [, $invoice, $attempt] = $this->scene();
        $this->unconfigurePaystack();
        $raw = json_encode($this->chargeSuccess($attempt));

        $this->webhook($raw, hash_hmac('sha512', $raw, ''))->assertUnauthorized();
        $this->webhook($raw, hash_hmac('sha512', $raw, $this->paystackSecret))->assertUnauthorized();

        $this->assertSame('open', $invoice->fresh()->status);
    }

    public function test_a_refused_live_key_cannot_be_used_to_accept_webhooks(): void
    {
        [, $invoice, $attempt] = $this->scene();
        $live = $this->liveKey();
        $this->configurePaystack($live, allowLive: false);
        $raw = json_encode($this->chargeSuccess($attempt));

        $this->webhook($raw, $this->sign($raw, $live))->assertUnauthorized();

        $this->assertSame('open', $invoice->fresh()->status);
    }

    public function test_invalid_json_with_a_valid_signature_is_a_400(): void
    {
        $this->webhook('{"event": "charge.success", "data": ')->assertStatus(400);
        $this->webhook('"just a string"')->assertStatus(400);
        $this->webhook('{"data": {}}')->assertStatus(400);
        $this->assertSame(0, $this->events()->count());
    }

    public function test_the_route_is_outside_csrf_session_and_auth(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.paystack');

        $this->assertSame('webhooks/paystack', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $middleware = $route->gatherMiddleware();
        $this->assertNotContains('web', $middleware);
        $this->assertNotContains('auth', $middleware);
        $this->assertNotContains('auth:sanctum', $middleware);
        $this->assertContains('throttle:600,1', $middleware);
    }

    // ---- idempotency and races -----------------------------------------------------------------

    public function test_the_same_webhook_twice_applies_exactly_once(): void
    {
        [, $invoice, $attempt] = $this->scene(100000);
        $body = $this->chargeSuccess($attempt);

        $this->webhook($body)->assertOk();
        $this->webhook($body)->assertOk();

        $this->assertSame(1, BillingPayment::withoutGlobalScope('team')->count());
        $this->assertSame(1, $this->events()->count());
        $this->assertSame(100000, $invoice->fresh()->amount_paid_cents);
        $this->assertSame(1, BillingAuditEvent::where('action', 'payment.recorded')->count());
    }

    public function test_callback_then_webhook_applies_once(): void
    {
        [$owner, $invoice, $attempt] = $this->scene(100000);
        $this->fakeVerify($attempt);

        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]))->assertRedirect();
        $this->webhook($this->chargeSuccess($attempt))->assertOk();

        $this->assertSame(1, BillingPayment::withoutGlobalScope('team')->count());
        $this->assertSame(1, $this->events()->count());
        $this->assertSame(100000, $invoice->fresh()->amount_paid_cents);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_webhook_then_callback_applies_once(): void
    {
        [$owner, $invoice, $attempt] = $this->scene(100000);

        $this->webhook($this->chargeSuccess($attempt))->assertOk();
        $this->fakeVerify($attempt);
        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('billing_notice');

        $this->assertSame(1, BillingPayment::withoutGlobalScope('team')->count());
        $this->assertSame(1, $this->events()->count());
        $this->assertSame(100000, $invoice->fresh()->amount_paid_cents);
    }

    public function test_a_lost_race_on_the_unique_index_becomes_a_replay_not_an_error(): void
    {
        [, $invoice, $attempt] = $this->scene(100000);

        // The other delivery got in first: the event row exists, the attempt row has not been marked yet.
        $this->webhook($this->chargeSuccess($attempt))->assertOk();
        $attempt->forceFill(['status' => 'initiated', 'completed_at' => null])->save();

        $this->webhook($this->chargeSuccess($attempt))->assertOk();

        $this->assertSame(1, BillingPayment::withoutGlobalScope('team')->count());
        $this->assertSame(100000, $invoice->fresh()->amount_paid_cents);
        $this->assertSame('succeeded', $attempt->fresh()->status);
    }

    public function test_a_replay_with_different_facts_is_held_never_applied(): void
    {
        [, $invoice, $attempt] = $this->scene(100000);
        $this->webhook($this->chargeSuccess($attempt))->assertOk();
        $attempt->forceFill(['status' => 'initiated'])->save();

        $this->webhook($this->chargeSuccess($attempt, ['amount' => 5]))->assertOk();

        $this->assertSame(100000, $invoice->fresh()->amount_paid_cents);
        $this->assertSame(1, $this->events()->where('status', 'unmatched')->count());
        $this->assertSame(1, BillingPayment::withoutGlobalScope('team')->count());
    }

    public function test_paying_in_full_by_webhook_resolves_the_dunning_case(): void
    {
        [, $invoice, $attempt] = $this->scene();
        $invoice->forceFill(['due_date' => now()->subDays(3)])->save();
        $case = DunningCase::create(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open']);

        $this->webhook($this->chargeSuccess($attempt))->assertOk();

        $this->assertSame('resolved', $case->fresh()->status);
    }

    // ---- business conditions never 5xx -------------------------------------------------------------

    public function test_an_unknown_reference_is_recorded_as_unmatched_and_acknowledged(): void
    {
        [, $invoice, $attempt] = $this->scene();
        $body = $this->chargeSuccess($attempt, ['reference' => 'not-one-of-ours-123', 'id' => 777001]);

        $this->webhook($body)->assertOk();
        $this->webhook($body)->assertOk();

        $held = $this->events()->sole();
        $this->assertSame('unmatched', $held->status);
        $this->assertSame('unknown_reference', $held->reason);
        $this->assertNull($held->invoice_id);
        $this->assertNull($held->team_id);
        $this->assertSame(100000, $held->amount_cents);
        $this->assertSame('ZAR', $held->currency);
        $this->assertSame('not-one-of-ours-123', $held->payload['reference']);
        $this->assertSame('open', $invoice->fresh()->status);
    }

    public function test_amount_and_currency_mismatches_are_unmatched_with_the_invoice_noted(): void
    {
        foreach ([['amount' => 5000, 'id' => 880001, 'why' => 'amount_mismatch'], ['currency' => 'NGN', 'id' => 880002, 'why' => 'currency_mismatch']] as $case) {
            [, $invoice, $attempt] = $this->scene();
            $why = $case['why'];
            unset($case['why']);

            $this->webhook($this->chargeSuccess($attempt, $case))->assertOk();

            $held = $this->events()->where('invoice_id', $invoice->id)->sole();
            $this->assertSame(['unmatched', $why], [$held->status, $held->reason]);
            $this->assertSame($invoice->team_id, $held->team_id);
            $this->assertSame('open', $invoice->fresh()->status);
            $this->assertSame('initiated', $attempt->fresh()->status);
        }
    }

    public function test_a_charge_success_body_whose_status_is_not_success_is_held(): void
    {
        [, $invoice, $attempt] = $this->scene();

        $this->webhook($this->chargeSuccess($attempt, ['status' => 'failed']))->assertOk();

        $this->assertSame('status_not_success', $this->events()->sole()->reason);
        $this->assertSame('open', $invoice->fresh()->status);
    }

    public function test_an_overpayment_arriving_by_webhook_is_needs_review_with_a_200(): void
    {
        [, $invoice, $attempt] = $this->scene(100000);
        $invoice->forceFill(['amount_paid_cents' => 60000])->save();

        $this->webhook($this->chargeSuccess($attempt))->assertOk();

        $held = $this->events()->sole();
        $this->assertSame(['needs_review', 'overpayment'], [$held->status, $held->reason]);
        $this->assertSame(60000, $invoice->fresh()->amount_paid_cents);
        $this->assertSame('needs_review', $attempt->fresh()->status);
    }

    public function test_a_partial_attempt_applies_and_the_invoice_stays_open(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);
        $attempt = $this->makeAttempt($invoice, ['amount_cents' => 40000]);

        $this->webhook($this->chargeSuccess($attempt))->assertOk();

        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame(40000, $invoice->fresh()->amount_paid_cents);
    }

    public function test_events_we_do_not_act_on_are_acknowledged_and_ignored(): void
    {
        [, $invoice, $attempt] = $this->scene();

        foreach (['transfer.success', 'subscription.create', 'charge.dispute.create', 'refund.pending', 'refund.failed'] as $event) {
            $this->webhook($this->chargeSuccess($attempt, [], $event))->assertOk();
        }

        $this->assertSame(0, $this->events()->count());
        $this->assertSame('open', $invoice->fresh()->status);
    }

    // ---- refunds -------------------------------------------------------------------------------

    public function test_refund_processed_is_recorded_for_review_and_never_changes_the_invoice(): void
    {
        [, $invoice, $attempt] = $this->scene(100000);
        $this->webhook($this->chargeSuccess($attempt))->assertOk();

        $refund = ['event' => 'refund.processed', 'data' => [
            'status' => 'processed',
            'transaction_reference' => $attempt->reference,
            'refund_reference' => 'rf_ref_0001',
            'amount' => '100000',
            'currency' => 'ZAR',
            'customer' => ['email' => 'private@example.test'],
        ]];
        $this->webhook($refund)->assertOk();
        $this->webhook($refund)->assertOk();

        $held = $this->events()->where('type', 'refund')->sole();
        $this->assertSame('needs_review', $held->status);
        $this->assertSame('refund_not_modelled', $held->reason);
        $this->assertSame($invoice->id, $held->invoice_id);
        $this->assertSame($invoice->team_id, $held->team_id);
        $this->assertSame(100000, $held->amount_cents);
        $this->assertNull($held->payment_id);
        $this->assertStringNotContainsString('private@example.test', json_encode($held->payload));

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status, 'A refund is never auto-applied to the invoice.');
        $this->assertSame(100000, $invoice->amount_paid_cents);
        $this->assertDatabaseHas('billing_audit_events', ['action' => 'payment.refund_flagged', 'subject_id' => $invoice->id]);
    }

    public function test_a_refund_for_an_unknown_transaction_is_still_recorded_and_acknowledged(): void
    {
        $this->webhook(['event' => 'refund.processed', 'data' => ['status' => 'processed', 'transaction' => ['reference' => 'ghost-ref-1'], 'amount' => 2500, 'currency' => 'ZAR']])->assertOk();

        $held = $this->events()->sole();
        $this->assertSame(['refund', 'needs_review', 'unknown_reference'], [$held->type, $held->status, $held->reason]);
        $this->assertNull($held->invoice_id);
        $this->assertSame(2500, $held->amount_cents);
    }
}
