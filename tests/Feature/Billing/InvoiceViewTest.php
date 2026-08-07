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
            'team_id' => $team->id,
            'invoice_number' => 'INV-100',
            'status' => 'open',
            'total' => 100,
        ]);

        $this->get(route('invoices.show', $invoice))->assertRedirect('/login');
    }

    public function test_team_member_can_view_own_invoice(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $invoice = BillingInvoice::create([
            'team_id' => $team->id,
            'invoice_number' => 'INV-101',
            'status' => 'paid',
            'total' => 250,
            'paid_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertViewIs('invoices.show')
            ->assertSee('INV-101');
    }

    public function test_user_cannot_view_another_teams_invoice(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $otherTeam = Team::factory()->create();

        $invoice = BillingInvoice::create([
            'team_id' => $otherTeam->id,
            'invoice_number' => 'INV-102',
            'status' => 'open',
            'total' => 500,
        ]);

        // As of BillingInvoice's HasTeamScope global scope, cross-team access
        // 404s rather than 403ing: implicit route-model binding queries
        // through the scope too, so another team's invoice is invisible
        // before BillingInvoicePolicy ever runs. This is intentionally a
        // stronger, fail-closed posture than the old assertForbidden()
        // behavior -- it no longer depends on InvoiceController@show
        // remembering to call Gate::authorize().
        $this->actingAs($user)
            ->get(route('invoices.show', $invoice))
            ->assertNotFound();
    }

    public function test_invoice_search_filters_by_invoice_number(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        BillingInvoice::create([
            'team_id' => $team->id,
            'invoice_number' => 'INV-200',
            'status' => 'open',
            'total' => 10,
        ]);
        BillingInvoice::create([
            'team_id' => $team->id,
            'invoice_number' => 'INV-999',
            'status' => 'open',
            'total' => 20,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('INV-200')->assertSee('INV-999');
    }

    /**
     * Proves HasTeamScope itself is load-bearing, independent of any Policy
     * or controller check: querying BillingInvoice directly as a member of
     * another team, with no Gate::authorize() anywhere in the path, still
     * cannot see the row. This is the property that makes the scope
     * "defense in depth" rather than decorative -- it holds even if a
     * future route or Livewire component forgets to check authorization
     * entirely.
     */
    public function test_scope_alone_blocks_cross_team_access_even_without_a_policy_check(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $ownTeam = $owner->currentTeam;
        $attacker = User::factory()->withPersonalTeam()->create();

        $invoice = BillingInvoice::create([
            'team_id' => $ownTeam->id,
            'invoice_number' => 'INV-300',
            'status' => 'open',
            'total' => 750,
        ]);

        $this->actingAs($attacker);

        $this->assertNull(BillingInvoice::find($invoice->id));
        $this->assertSame(0, BillingInvoice::query()->count());

        $this->actingAs($owner);

        $this->assertNotNull(BillingInvoice::find($invoice->id));
        $this->assertSame(1, BillingInvoice::query()->count());
    }
}
