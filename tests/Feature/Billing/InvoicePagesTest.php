<?php

namespace Tests\Feature\Billing;

use App\Livewire\Billing\InvoiceActions;
use App\Livewire\Billing\InvoiceTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class InvoicePagesTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    public function test_the_invoice_list_shows_only_the_current_teams_invoices(): void
    {
        $owner = $this->makeOwner();
        $other = $this->makeOwner();
        $this->makeInvoice($owner->currentTeam, ['invoice_number' => 'MINE-INVOICE-LONG-5521']);
        $this->makeInvoice($other->currentTeam, ['invoice_number' => 'THEIRS-INVOICE-LONG-8832']);

        $this->actingAs($owner)->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('MINE-INVOICE-LONG-5521')
            ->assertDontSee('THEIRS-INVOICE-LONG-8832');
    }

    public function test_the_invoice_list_requires_sign_in(): void
    {
        $this->get(route('invoices.index'))->assertRedirect('/login');
    }

    public function test_amounts_are_shown_in_each_invoices_own_currency(): void
    {
        $owner = $this->makeOwner();
        $this->makeInvoice($owner->currentTeam, ['currency' => 'USD', 'total_cents' => 123450]);
        $this->makeInvoice($owner->currentTeam, ['currency' => 'ZAR', 'total_cents' => 987654321]);

        $this->actingAs($owner)->get(route('invoices.index'))
            ->assertSee('USD 1,234.50')
            ->assertSee('ZAR 9,876,543.21');
    }

    public function test_drafts_are_visible_to_managers_but_not_to_viewers_or_editors(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $draft = $this->makeInvoice($team, ['status' => 'draft', 'invoice_number' => 'DRAFT-ONLY-MANAGERS-4417', 'issued_at' => null, 'due_date' => null]);

        $this->actingAs($owner)->get(route('invoices.index'))->assertSee('DRAFT-ONLY-MANAGERS-4417');
        $this->actingAs($owner)->get(route('invoices.show', $draft))->assertOk();

        foreach (['viewer', 'editor'] as $role) {
            $member = $this->makeMember($team, $role);
            $this->actingAs($member)->get(route('invoices.index'))->assertDontSee('DRAFT-ONLY-MANAGERS-4417');
            $this->actingAs($member)->get(route('invoices.show', $draft))->assertForbidden();
        }
    }

    public function test_a_viewer_can_read_an_open_invoice_but_sees_no_manager_actions(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $invoice = $this->makeInvoice($team, ['invoice_number' => 'OPEN-FOR-VIEWER-9902']);

        $this->actingAs($this->makeMember($team, 'viewer'))->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('OPEN-FOR-VIEWER-9902')
            ->assertDontSee('Void invoice');

        $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertSee('Void invoice');
    }

    public function test_the_detail_page_shows_line_items_tax_balance_and_period(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, [
            'subtotal_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000, 'amount_paid_cents' => 40000, 'tax_rate_bps' => 1500,
        ]);
        $invoice->items()->create(['description' => 'Distinct Platform Subscription Line', 'quantity' => 2, 'unit_price_cents' => 50000, 'amount_cents' => 100000, 'period' => '1 Nov 2026 to 1 Dec 2026']);

        $this->actingAs($owner)->get(route('invoices.show', $invoice))
            ->assertSee('Distinct Platform Subscription Line')
            ->assertSee('ZAR 1,000.00')
            ->assertSee('ZAR 150.00')
            ->assertSee('ZAR 1,150.00')
            ->assertSee('ZAR 400.00')
            ->assertSee('ZAR 750.00')
            ->assertSee('1 Nov 2026 to 1 Dec 2026');
    }

    public function test_the_print_view_is_a_standalone_page_with_the_issuer_and_customer(): void
    {
        config(['billing.issuer' => ['name' => 'Distinct Issuer Holdings', 'address' => ['12 Example Road', 'Pretoria'], 'tax_number' => 'VAT-4455667788', 'email' => null]]);
        $owner = $this->makeOwner();
        $owner->currentTeam->forceFill(['name' => 'Customer Team Distinct Name'])->save();
        $invoice = $this->makeInvoice($owner->currentTeam, ['invoice_number' => 'PRINT-ME-DISTINCT-3310']);

        $response = $this->actingAs($owner)->get(route('invoices.print', $invoice));

        $response->assertOk()
            ->assertSee('PRINT-ME-DISTINCT-3310')
            ->assertSee('Distinct Issuer Holdings')
            ->assertSee('12 Example Road')
            ->assertSee('VAT-4455667788')
            ->assertSee('Customer Team Distinct Name')
            ->assertDontSee('sidebar');
        $this->assertStringNotContainsString('Dot.Billing</div>', $response->getContent());
    }

    public function test_the_print_view_invents_no_issuer_when_none_is_configured(): void
    {
        config(['billing.issuer' => ['name' => null, 'address' => [], 'tax_number' => null, 'email' => null]]);
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam);

        $this->actingAs($owner)->get(route('invoices.print', $invoice))
            ->assertOk()
            ->assertDontSee('Tax number')
            ->assertDontSee('VAT');
    }

    public function test_another_teams_invoice_cannot_be_printed(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($this->makeOwner()->currentTeam);

        $this->get(route('invoices.print', $invoice))->assertRedirect('/login');
        $this->actingAs($owner)->get(route('invoices.print', $invoice))->assertNotFound();
    }

    public function test_a_manager_can_finalize_a_draft(): void
    {
        $owner = $this->makeOwner();
        $draft = $this->makeInvoice($owner->currentTeam, ['status' => 'draft', 'issued_at' => null, 'due_date' => null]);

        Livewire::actingAs($owner)->test(InvoiceActions::class, ['invoice' => $draft])
            ->call('finalize')
            ->assertHasNoErrors();

        $this->assertSame('open', $draft->fresh()->status);
        $this->assertNotNull($draft->fresh()->due_date);
    }

    public function test_a_manager_can_void_an_unpaid_invoice_with_a_reason(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam);

        Livewire::actingAs($owner)->test(InvoiceActions::class, ['invoice' => $invoice])
            ->call('void')
            ->assertHasErrors(['voidReason'])
            ->set('voidReason', 'Raised against the wrong company')
            ->call('void')
            ->assertHasNoErrors();

        $this->assertSame('void', $invoice->fresh()->status);
    }

    public function test_a_viewer_and_a_stranger_cannot_finalize_or_void(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $invoice = $this->makeInvoice($team);

        Livewire::actingAs($this->makeMember($team, 'viewer'))->test(InvoiceActions::class, ['invoice' => $invoice])
            ->set('voidReason', 'because')->call('void')->assertForbidden();

        $this->assertSame('open', $invoice->fresh()->status);
    }

    public function test_the_invoice_table_filters_by_status_and_searches_by_number(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $this->makeInvoice($team, ['invoice_number' => 'FILTER-PAID-LONG-1201', 'status' => 'paid', 'amount_paid_cents' => 50000, 'paid_at' => now()]);
        $this->makeInvoice($team, ['invoice_number' => 'FILTER-OPEN-LONG-1202']);

        Livewire::actingAs($owner)->test(InvoiceTable::class)
            ->set('filterStatus', 'paid')
            ->assertSee('FILTER-PAID-LONG-1201')
            ->assertDontSee('FILTER-OPEN-LONG-1202')
            ->set('filterStatus', '')
            ->set('search', 'OPEN-LONG')
            ->assertSee('FILTER-OPEN-LONG-1202')
            ->assertDontSee('FILTER-PAID-LONG-1201');
    }
}
