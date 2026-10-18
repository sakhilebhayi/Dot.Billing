<?php

namespace Tests\Feature\Billing;

use App\Livewire\Billing\RecordPaymentForm;
use App\Models\BillingPayment;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class ManualPaymentFormTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_manager_records_a_manual_payment_and_the_audit_row_names_them(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);

        Livewire::actingAs($owner)->test(RecordPaymentForm::class, ['invoice' => $invoice])
            ->set('amount', '400.00')
            ->set('method', 'eft')
            ->set('reference', 'EFT-REF-DISTINCT-60517')
            ->call('record')
            ->assertHasNoErrors();

        $this->assertSame(40000, $invoice->fresh()->amount_paid_cents);
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertDatabaseHas('billing_audit_events', ['action' => 'payment.recorded', 'actor_id' => $owner->id, 'subject_id' => $invoice->id]);
        $this->assertSame('manual', PaymentEvent::withoutGlobalScope('team')->sole()->provider);
    }

    public function test_the_form_starts_with_the_outstanding_balance(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000, 'amount_paid_cents' => 25050]);

        Livewire::actingAs($owner)->test(RecordPaymentForm::class, ['invoice' => $invoice])
            ->assertSet('amount', '749.50');
    }

    public function test_paying_the_balance_closes_the_invoice(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);

        Livewire::actingAs($owner)->test(RecordPaymentForm::class, ['invoice' => $invoice])
            ->call('record')
            ->assertHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_a_double_submit_of_the_same_form_is_recorded_once(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);

        $component = Livewire::actingAs($owner)->test(RecordPaymentForm::class, ['invoice' => $invoice])
            ->set('amount', '100.00');
        $token = $component->get('requestToken');

        $component->call('record');
        // The browser fires the click again before the first response arrives: same token, same form.
        $component->set('requestToken', $token)->set('amount', '100.00')->call('record');

        $this->assertSame(10000, $invoice->fresh()->amount_paid_cents);
        $this->assertSame(1, BillingPayment::withoutGlobalScope('team')->count());
    }

    public function test_bad_amounts_are_explained_not_guessed(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);

        foreach (['12,50', '-5', 'abc', '0', ''] as $bad) {
            Livewire::actingAs($owner)->test(RecordPaymentForm::class, ['invoice' => $invoice])
                ->set('amount', $bad)
                ->call('record')
                ->assertHasErrors(['amount']);
        }

        $this->assertSame(0, $invoice->fresh()->amount_paid_cents);
    }

    public function test_an_overpayment_is_refused_with_a_message(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);

        Livewire::actingAs($owner)->test(RecordPaymentForm::class, ['invoice' => $invoice])
            ->set('amount', '1000.01')
            ->call('record')
            ->assertHasErrors(['amount']);

        $this->assertSame(0, $invoice->fresh()->amount_paid_cents);
    }

    public function test_a_viewer_cannot_record_a_payment(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $invoice = $this->makeInvoice($team, ['total_cents' => 100000]);

        Livewire::actingAs($this->makeMember($team, 'viewer'))->test(RecordPaymentForm::class, ['invoice' => $invoice])
            ->call('record')
            ->assertForbidden();

        $this->assertSame(0, $invoice->fresh()->amount_paid_cents);
    }

    public function test_the_form_is_only_shown_to_managers_on_an_open_invoice(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $open = $this->makeInvoice($team, ['invoice_number' => 'OPEN-SHOWS-FORM-3301']);
        $paid = $this->makeInvoice($team, ['invoice_number' => 'PAID-NO-FORM-3302', 'status' => 'paid', 'amount_paid_cents' => 50000, 'paid_at' => now()]);

        $this->actingAs($owner)->get(route('invoices.show', $open))->assertSee('Record a manual payment');
        $this->actingAs($owner)->get(route('invoices.show', $paid))->assertDontSee('Record a manual payment');
        $this->actingAs($this->makeMember($team, 'viewer'))->get(route('invoices.show', $open))->assertDontSee('Record a manual payment');
    }

    public function test_recorded_payments_are_listed_on_the_invoice(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);

        Livewire::actingAs($owner)->test(RecordPaymentForm::class, ['invoice' => $invoice])
            ->set('amount', '250.00')->set('method', 'cash')->set('reference', 'RECEIPT-BOOK-DISTINCT-7714')
            ->call('record');

        $this->actingAs($owner)->get(route('invoices.show', $invoice))
            ->assertSee('RECEIPT-BOOK-DISTINCT-7714')
            ->assertSee('ZAR 250.00');
    }
}
