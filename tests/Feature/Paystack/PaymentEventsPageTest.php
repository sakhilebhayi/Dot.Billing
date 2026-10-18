<?php

namespace Tests\Feature\Paystack;

use App\Models\PaymentEvent;
use App\Services\Billing\RecordPayment;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PaystackFixtures;
use Tests\TestCase;

class PaymentEventsPageTest extends TestCase
{
    use BillingFixtures;
    use PaystackFixtures;
    use RefreshDatabase;

    private function held(array $attributes): PaymentEvent
    {
        return PaymentEvent::withoutGlobalScope('team')->create(array_merge([
            'provider' => 'paystack', 'type' => 'payment', 'status' => 'unmatched', 'reason' => 'unknown_reference',
            'amount_cents' => 100000, 'currency' => 'ZAR', 'received_at' => now(),
        ], $attributes));
    }

    public function test_operators_see_every_event_across_companies_with_trouble_called_out_first(): void
    {
        $this->configurePaystack();
        $operator = $this->makeOperator();
        $ownerA = $this->makeOwner();
        $ownerA->currentTeam->forceFill(['name' => 'Acme Mining Pty'])->save();
        $invoice = $this->makeInvoice($ownerA->currentTeam, ['invoice_number' => 'INV-2026-0042', 'total_cents' => 100000]);

        $this->held(['external_id' => 'held:x:1', 'reason' => 'amount_mismatch', 'team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'payload' => ['reference' => 'ref-unmatched-aa']]);
        $this->held(['external_id' => 'held:x:2', 'status' => 'needs_review', 'reason' => 'overpayment', 'type' => 'refund', 'payload' => ['reference' => 'ref-review-bb']]);
        $this->held(['external_id' => '991122', 'status' => 'applied', 'reason' => null, 'team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'amount_cents' => 33300, 'payload' => ['reference' => 'ref-applied-cc']]);

        $response = $this->actingAs($operator)->get(route('operator.payment-events.index'));

        $response->assertOk()
            ->assertSee('2 payment events need an operator')
            ->assertSee('ref-unmatched-aa')
            ->assertSee('ref-review-bb')
            ->assertSee('ref-applied-cc')
            ->assertSee('INV-2026-0042')
            ->assertSee('Acme Mining Pty')
            ->assertSee('ZAR 1,000.00')
            ->assertSee('ZAR 333.00')
            ->assertSee('TEST MODE');
        $this->assertStringContainsString('class="pill overdue"', $response->getContent());
        $this->assertStringContainsString('Amount mismatch', $response->getContent());
    }

    public function test_the_attention_filter_hides_applied_events(): void
    {
        $operator = $this->makeOperator();
        $this->held(['external_id' => 'held:x:1', 'payload' => ['reference' => 'ref-trouble-dd']]);
        $this->held(['external_id' => '55', 'status' => 'applied', 'reason' => null, 'payload' => ['reference' => 'ref-fine-ee']]);

        $this->actingAs($operator)->get(route('operator.payment-events.index', ['show' => 'attention']))
            ->assertOk()->assertSee('ref-trouble-dd')->assertDontSee('ref-fine-ee');
    }

    public function test_with_nothing_wrong_the_page_says_so(): void
    {
        $operator = $this->makeOperator();

        $this->actingAs($operator)->get(route('operator.payment-events.index'))
            ->assertOk()->assertSee('No payment events need an operator')->assertSee('No payment events have been recorded yet');
    }

    public function test_the_page_is_for_platform_operators_only_and_is_read_only(): void
    {
        $owner = $this->makeOwner();
        $this->held(['external_id' => 'held:x:1']);

        $this->get(route('operator.payment-events.index'))->assertRedirect('/login');
        $this->actingAs($owner)->get(route('operator.payment-events.index'))->assertForbidden();

        $page = $this->actingAs($this->makeOperator())->get(route('operator.payment-events.index'))->assertOk();
        preg_match('/<main>(.*)<\/main>/s', $page->getContent(), $main);
        $this->assertNotEmpty($main[1] ?? '');
        $this->assertStringNotContainsString('<form', $main[1]);
        $this->assertStringNotContainsString('<button', $main[1]);
        $this->assertStringNotContainsString('wire:click', $main[1]);
    }

    public function test_the_operator_nav_links_to_the_page(): void
    {
        $this->actingAs($this->makeOperator())->get(route('operator.plans.index'))
            ->assertSee(route('operator.payment-events.index'), false);
        $this->actingAs($this->makeOwner())->get(route('dashboard'))
            ->assertDontSee(route('operator.payment-events.index'), false);
    }

    public function test_manual_payments_show_as_applied(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 5000]);
        app(RecordPayment::class)->apply($invoice, new Money(5000, 'ZAR'), method: 'eft');
        $this->fakeHttp();

        $event = PaymentEvent::withoutGlobalScope('team')->sole();
        $this->assertSame('applied', $event->status);
        $this->assertSame('payment', $event->type);
    }
}
