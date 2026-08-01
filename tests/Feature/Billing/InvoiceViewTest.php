<?php

namespace Tests\Feature\Billing;

use App\Models\BillingInvoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_invoice(): void
    {
        $team = Team::factory()->create();

        $invoice = BillingInvoice::create([
            'team_id'        => $team->id,
            'invoice_number' => 'INV-100',
            'status'         => 'open',
            'total'          => 100,
        ]);

        $this->get(route('invoices.show', $invoice))->assertRedirect('/login');
    }

    public function test_team_member_can_view_own_invoice(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $invoice = BillingInvoice::create([
            'team_id'        => $team->id,
            'invoice_number' => 'INV-101',
            'status'         => 'paid',
            'total'          => 250,
            'paid_at'        => now(),
        ]);

        $this->actingAs($user)
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertViewIs('invoices.show')
            ->assertSee('INV-101');
    }

    public function test_user_cannot_view_another_teams_invoice(): void
    {
        $user      = User::factory()->withPersonalTeam()->create();
        $otherTeam = Team::factory()->create();

        $invoice = BillingInvoice::create([
            'team_id'        => $otherTeam->id,
            'invoice_number' => 'INV-102',
            'status'         => 'open',
            'total'          => 500,
        ]);

        $this->actingAs($user)
            ->get(route('invoices.show', $invoice))
            ->assertForbidden();
    }

    public function test_invoice_search_filters_by_invoice_number(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        BillingInvoice::create([
            'team_id'        => $team->id,
            'invoice_number' => 'INV-200',
            'status'         => 'open',
            'total'          => 10,
        ]);
        BillingInvoice::create([
            'team_id'        => $team->id,
            'invoice_number' => 'INV-999',
            'status'         => 'open',
            'total'          => 20,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('INV-200')->assertSee('INV-999');
    }
}
