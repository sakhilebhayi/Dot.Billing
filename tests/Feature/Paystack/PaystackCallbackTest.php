<?php

namespace Tests\Feature\Paystack;

use App\Models\BillingPayment;
use App\Models\DunningCase;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PaystackFixtures;
use Tests\TestCase;

class PaystackCallbackTest extends TestCase
{
    use BillingFixtures;
    use PaystackFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configurePaystack();
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

    /** Owner, an open invoice and its live attempt. */
    private function scene(int $total = 100000): array
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => $total]);
        $attempt = $this->makeAttempt($invoice, [], $owner);

        return [$owner, $invoice, $attempt];
    }

    public function test_a_verified_success_records_the_payment_and_closes_the_invoice(): void
    {
        [$owner, $invoice, $attempt] = $this->scene(100000);
        $this->fakeVerify($attempt);

        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('billing_notice');

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(100000, $invoice->amount_paid_cents);
        $event = $this->events()->sole();
        $this->assertSame('paystack', $event->provider);
        $this->assertSame('4099260516', $event->external_id);
        $this->assertSame('applied', $event->status);
        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->completed_at);
        $this->assertSame('paystack', BillingPayment::withoutGlobalScope('team')->sole()->method);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/transaction/verify/'.$attempt->reference));
    }

    public function test_the_stored_payload_keeps_only_safe_fields_and_no_card_data(): void
    {
        [$owner, , $attempt] = $this->scene();
        $this->fakeVerify($attempt);

        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]));

        $payload = $this->events()->sole()->payload;
        $this->assertSame($attempt->reference, $payload['reference']);
        $this->assertSame('card', $payload['channel']);
        $this->assertStringNotContainsString('AUTH_not_for_storage', json_encode($payload));
        $this->assertStringNotContainsString('4081', json_encode($payload));
    }

    public function test_query_parameters_are_never_trusted_for_status(): void
    {
        [$owner, $invoice, $attempt] = $this->scene();
        $this->fakeVerify($attempt, ['status' => 'failed']);

        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid, 'status' => 'success', 'trxref' => $attempt->reference, 'reference' => 'someone-elses-ref']))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('billing_error');

        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame(0, $this->events()->count());
        $this->assertSame('failed', $attempt->fresh()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/verify/'.$attempt->reference), 'Verification used our own stored reference.');
    }

    public function test_every_mismatch_is_held_for_an_operator_and_nothing_is_applied(): void
    {
        $cases = [
            'amount' => [['amount' => 99999], 'amount_mismatch'],
            'currency' => [['currency' => 'USD'], 'currency_mismatch'],
            'reference' => [['reference' => 'a-different-reference'], 'reference_mismatch'],
        ];

        foreach ($cases as $name => [$override, $reason]) {
            [$owner, $invoice, $attempt] = $this->scene(100000);
            $this->fakeVerify($attempt, $override + ['id' => 5000 + count($override) + strlen($name)]);

            $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]))
                ->assertRedirect(route('invoices.show', $invoice))
                ->assertSessionHas('billing_error');

            $this->assertSame('open', $invoice->fresh()->status, $name);
            $this->assertSame(0, $invoice->fresh()->amount_paid_cents, $name);
            $held = $this->events()->where('invoice_id', $invoice->id)->sole();
            $this->assertSame('unmatched', $held->status, $name);
            $this->assertSame($reason, $held->reason, $name);
            $this->assertNull($held->payment_id, $name);
        }
    }

    public function test_abandoned_and_pending_transactions_change_nothing_and_the_attempt_stays_usable(): void
    {
        foreach (['abandoned', 'ongoing', 'pending'] as $status) {
            [$owner, $invoice, $attempt] = $this->scene();
            $this->fakeVerify($attempt, ['status' => $status]);

            $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]))
                ->assertRedirect(route('invoices.show', $invoice))
                ->assertSessionHas('billing_error');

            $this->assertSame('initiated', $attempt->fresh()->status, $status);
            $this->assertSame('open', $invoice->fresh()->status, $status);
        }

        $this->assertSame(0, $this->events()->count());
    }

    public function test_paystack_being_down_changes_nothing_and_tells_the_payer_honestly(): void
    {
        [$owner, $invoice, $attempt] = $this->scene();

        foreach ([fn () => Http::response('', 500), fn () => throw new ConnectionException('timed out')] as $failure) {
            $this->fakeHttp(['*' => $failure]);

            $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]))
                ->assertRedirect(route('invoices.show', $invoice))
                ->assertSessionHas('billing_error');
        }

        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame('initiated', $attempt->fresh()->status);
        $this->assertSame(0, $this->events()->count());
    }

    public function test_visiting_the_callback_twice_applies_once(): void
    {
        [$owner, $invoice, $attempt] = $this->scene(100000);
        $this->fakeVerify($attempt);

        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]));
        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]))->assertSessionHas('billing_notice');

        $this->assertSame(1, BillingPayment::withoutGlobalScope('team')->count());
        $this->assertSame(100000, $invoice->fresh()->amount_paid_cents);
    }

    public function test_paying_in_full_resolves_the_dunning_case(): void
    {
        [$owner, $invoice, $attempt] = $this->scene();
        $invoice->forceFill(['due_date' => now()->subDays(3)])->save();
        $case = DunningCase::create(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open']);
        $this->fakeVerify($attempt);

        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]));

        $this->assertSame('resolved', $case->fresh()->status);
    }

    public function test_a_partial_attempt_applies_and_the_invoice_stays_open(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);
        $attempt = $this->makeAttempt($invoice, ['amount_cents' => 25000], $owner);
        $this->fakeVerify($attempt);

        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]))->assertSessionHas('billing_notice');

        $invoice->refresh();
        $this->assertSame('open', $invoice->status);
        $this->assertSame(25000, $invoice->amount_paid_cents);
        $this->assertSame('succeeded', $attempt->fresh()->status);
    }

    public function test_an_overpayment_is_held_as_needs_review(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);
        $attempt = $this->makeAttempt($invoice, [], $owner);
        // A manual payment lands while the customer is on Paystack's page: the balance shrank.
        $invoice->forceFill(['amount_paid_cents' => 40000])->save();
        $this->fakeVerify($attempt);

        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('billing_error');

        $invoice->refresh();
        $this->assertSame(40000, $invoice->amount_paid_cents);
        $this->assertSame('open', $invoice->status);
        $held = $this->events()->sole();
        $this->assertSame('needs_review', $held->status);
        $this->assertSame('overpayment', $held->reason);
        $this->assertSame($invoice->id, $held->invoice_id);
        $this->assertSame(100000, $held->amount_cents);
        $this->assertSame('needs_review', $attempt->fresh()->status);
    }

    public function test_paying_an_invoice_that_was_settled_meanwhile_is_held_not_lost(): void
    {
        [$owner, $invoice, $attempt] = $this->scene();
        $invoice->forceFill(['status' => 'paid', 'amount_paid_cents' => 100000])->save();
        $this->fakeVerify($attempt);

        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => $attempt->uuid]))->assertSessionHas('billing_error');

        $held = $this->events()->sole();
        $this->assertSame('needs_review', $held->status);
        $this->assertSame('invoice_not_payable', $held->reason);
    }

    public function test_the_callback_requires_sign_in_and_a_manager_of_the_attempts_company(): void
    {
        $this->fakeHttp();
        [$owner, $invoice, $attempt] = $this->scene();
        $url = route('paystack.callback', ['attempt' => $attempt->uuid]);

        $this->get($url)->assertRedirect('/login');

        $outsider = $this->makeOwner();
        $this->actingAs($outsider)->get($url)->assertNotFound();

        $viewer = $this->makeMember($owner->currentTeam, 'viewer');
        $this->actingAs($viewer)->get($url)->assertForbidden();

        $this->actingAs($owner)->get(route('paystack.callback', ['attempt' => 'not-a-real-uuid']))->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame('open', $invoice->fresh()->status);
    }
}
