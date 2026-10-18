<?php

namespace Tests\Feature\Paystack;

use App\Models\BillingAuditEvent;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PaystackFixtures;
use Tests\TestCase;

class PayInvoiceTest extends TestCase
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

    private function fakeInitialize(string $code = 'chk_code_1'): void
    {
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => function (Request $request) use ($code) {
            return Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/'.$code, 'access_code' => $code, 'reference' => $request->data()['reference']],
            ]);
        }]);
    }

    private function attempts()
    {
        return PaymentAttempt::withoutGlobalScope('team');
    }

    public function test_pay_now_creates_an_attempt_for_the_current_balance_and_redirects_to_paystack(): void
    {
        $this->fakeInitialize();
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000, 'amount_paid_cents' => 30000]);

        $response = $this->actingAs($owner)->post(route('invoices.pay', $invoice));

        $response->assertRedirect('https://checkout.paystack.com/chk_code_1');
        $attempt = $this->attempts()->sole();
        $this->assertSame($invoice->id, $attempt->invoice_id);
        $this->assertSame($invoice->team_id, $attempt->team_id);
        $this->assertSame('paystack', $attempt->provider);
        $this->assertSame(70000, $attempt->amount_cents, 'The attempt is for the balance, not the total.');
        $this->assertSame('ZAR', $attempt->currency);
        $this->assertSame('initiated', $attempt->status);
        $this->assertSame($owner->id, $attempt->initiated_by);
        $this->assertSame('https://checkout.paystack.com/chk_code_1', $attempt->authorization_url);
        $this->assertTrue($attempt->expires_at->equalTo(now()->addMinutes(30)));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9.=-]+$/', $attempt->reference, 'Paystack allows only - . = and alphanumerics.');

        Http::assertSent(fn (Request $r) => $r->data()['amount'] === 70000 && $r->data()['email'] === $owner->email && $r->data()['reference'] === $attempt->reference);
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame(30000, $invoice->fresh()->amount_paid_cents, 'Starting a payment never touches the invoice.');
        $this->assertDatabaseHas('billing_audit_events', ['action' => 'payment.initiated', 'actor_id' => $owner->id, 'subject_id' => $invoice->id]);
    }

    public function test_the_authorization_url_is_not_serialised_with_the_attempt(): void
    {
        $owner = $this->makeOwner();
        $attempt = $this->makeAttempt($this->makeInvoice($owner->currentTeam));

        $this->assertArrayNotHasKey('authorization_url', $attempt->toArray());
    }

    public function test_a_double_pay_now_reuses_the_live_attempt_and_calls_paystack_once(): void
    {
        $this->fakeInitialize();
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam);

        $first = $this->actingAs($owner)->post(route('invoices.pay', $invoice));
        $second = $this->actingAs($owner)->post(route('invoices.pay', $invoice));

        $first->assertRedirect('https://checkout.paystack.com/chk_code_1');
        $second->assertRedirect('https://checkout.paystack.com/chk_code_1');
        $this->assertSame(1, $this->attempts()->count());
        Http::assertSentCount(1);
    }

    public function test_an_expired_attempt_is_retired_and_replaced(): void
    {
        $this->fakeInitialize('fresh_code');
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam);
        $old = $this->makeAttempt($invoice, ['expires_at' => now()->subMinute()]);

        $this->actingAs($owner)->post(route('invoices.pay', $invoice))->assertRedirect('https://checkout.paystack.com/fresh_code');

        $this->assertSame('expired', $old->fresh()->status);
        $this->assertSame(2, $this->attempts()->count());
        $this->assertSame(1, $this->attempts()->where('status', 'initiated')->count());
    }

    public function test_a_live_attempt_for_a_stale_balance_is_superseded(): void
    {
        $this->fakeInitialize('new_amount');
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);
        $stale = $this->makeAttempt($invoice);
        $invoice->forceFill(['amount_paid_cents' => 40000])->save();

        $this->actingAs($owner)->post(route('invoices.pay', $invoice))->assertRedirect('https://checkout.paystack.com/new_amount');

        $this->assertSame('expired', $stale->fresh()->status);
        $this->assertSame(60000, $this->attempts()->where('status', 'initiated')->sole()->amount_cents);
    }

    public function test_only_owners_and_admins_can_pay_now(): void
    {
        $this->fakeInitialize();
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $invoice = $this->makeInvoice($team);

        foreach (['viewer', 'editor'] as $role) {
            $member = $this->makeMember($team, $role);
            $this->actingAs($member)->post(route('invoices.pay', $invoice))->assertForbidden();
        }

        $outsider = $this->makeOwner();
        $this->actingAs($outsider)->post(route('invoices.pay', $invoice))->assertNotFound();
        $this->assertSame(0, $this->attempts()->count());
        Http::assertNothingSent();

        $admin = $this->makeMember($team, 'admin');
        $this->actingAs($admin)->post(route('invoices.pay', $invoice))->assertRedirect('https://checkout.paystack.com/chk_code_1');
        $this->assertSame($admin->id, $this->attempts()->sole()->initiated_by);
    }

    public function test_pay_now_requires_sign_in(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam);

        $this->post(route('invoices.pay', $invoice))->assertRedirect('/login');
    }

    public function test_another_companys_invoice_cannot_be_paid_by_outsiders_or_by_its_viewers(): void
    {
        $this->fakeHttp();
        $ownerA = $this->makeOwner();
        $ownerB = $this->makeOwner();
        $invoiceB = $this->makeInvoice($ownerB->currentTeam);

        // Owner A, signed in on their own team, targets company B's invoice id.
        $this->actingAs($ownerA)->post(route('invoices.pay', $invoiceB))->assertNotFound();

        // A read-only member of company B cannot pay either.
        $viewerInB = $this->makeMember($ownerB->currentTeam, 'viewer');
        $this->actingAs($viewerInB)->post(route('invoices.pay', $invoiceB))->assertForbidden();

        $this->assertSame(0, $this->attempts()->count());
        Http::assertNothingSent();
    }

    public function test_draft_void_and_paid_invoices_cannot_be_paid(): void
    {
        $this->fakeHttp();
        $owner = $this->makeOwner();

        foreach (['draft', 'void', 'paid'] as $status) {
            $invoice = $this->makeInvoice($owner->currentTeam, ['status' => $status]);

            $this->actingAs($owner)->post(route('invoices.pay', $invoice))->assertRedirect(route('invoices.show', $invoice));
        }

        $this->assertSame(0, $this->attempts()->count());
        Http::assertNothingSent();
    }

    public function test_a_fully_settled_open_invoice_has_nothing_to_pay(): void
    {
        $this->fakeHttp();
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 5000, 'amount_paid_cents' => 5000]);

        $this->actingAs($owner)->post(route('invoices.pay', $invoice))->assertSessionHas('billing_error');

        $this->assertSame(0, $this->attempts()->count());
        Http::assertNothingSent();
    }

    public function test_with_no_key_pay_now_does_nothing_and_says_why(): void
    {
        $this->fakeHttp();
        $this->unconfigurePaystack();
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam);

        $this->actingAs($owner)->post(route('invoices.pay', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('billing_error');

        $this->assertSame(0, $this->attempts()->count());
        Http::assertNothingSent();
    }

    public function test_paystack_down_gives_a_friendly_error_and_leaves_no_state_behind(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);

        foreach ([fn () => Http::response('', 500), fn () => throw new ConnectionException('cURL error 28: timed out')] as $failure) {
            $this->fakeHttp(['*' => $failure]);

            $this->actingAs($owner)->post(route('invoices.pay', $invoice))
                ->assertRedirect(route('invoices.show', $invoice))
                ->assertSessionHas('billing_error');
        }

        $this->assertSame(0, $this->attempts()->count(), 'A failed start must not leave a dangling attempt.');
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->amount_paid_cents);
        $this->assertSame(0, BillingAuditEvent::where('action', 'payment.initiated')->count());
    }

    public function test_the_invoice_page_offers_pay_now_with_a_test_mode_badge_to_managers_only_when_payable(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $invoice = $this->makeInvoice($team, ['total_cents' => 100000, 'amount_paid_cents' => 25000]);

        $this->actingAs($owner)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Pay now')
            ->assertSee('ZAR 750.00')
            ->assertSee('TEST MODE')
            ->assertSee(route('invoices.pay', $invoice), false);

        foreach (['viewer', 'editor'] as $role) {
            $this->actingAs($this->makeMember($team, $role))->get(route('invoices.show', $invoice))
                ->assertOk()->assertDontSee('Pay now')->assertDontSee(route('invoices.pay', $invoice), false);
        }

        foreach (['paid', 'void'] as $status) {
            $other = $this->makeInvoice($team, ['status' => $status, 'amount_paid_cents' => $status === 'paid' ? 100000 : 0]);
            $this->actingAs($owner)->get(route('invoices.show', $other))->assertOk()->assertDontSee('Pay now');
        }

        $draft = $this->makeInvoice($team, ['status' => 'draft']);
        $this->actingAs($owner)->get(route('invoices.show', $draft))->assertOk()->assertDontSee('Pay now');
    }

    public function test_with_no_key_the_button_is_hidden_and_the_page_says_so_honestly(): void
    {
        $this->unconfigurePaystack();
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam);

        $this->actingAs($owner)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('Pay now')
            ->assertDontSee(route('invoices.pay', $invoice), false)
            ->assertSee('Online payment is not set up');
    }

    public function test_with_a_refused_live_key_the_button_is_hidden_and_the_page_explains(): void
    {
        $this->configurePaystack($this->liveKey(), allowLive: false);
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam);

        $this->actingAs($owner)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('Pay now')
            ->assertSee('PAYSTACK_ALLOW_LIVE')
            ->assertDontSee($this->liveKey());
    }

    public function test_a_waiting_attempt_is_mentioned_and_flash_messages_render(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam);
        $this->makeAttempt($invoice, ['created_at' => now()->subMinutes(5)]);

        $this->actingAs($owner)->withSession(['billing_error' => 'Flash error text for the test.', 'billing_notice' => 'Flash notice text for the test.'])
            ->get(route('invoices.show', $invoice))
            ->assertSee('waiting')
            ->assertSee('Flash error text for the test.')
            ->assertSee('Flash notice text for the test.');
    }
}
