<?php

namespace Tests\Feature\Billing;

use App\Models\BillingAlert;
use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->withPersonalTeam()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_dashboard(): void
    {
        $this->actingAs($this->user)->get('/dashboard')->assertOk()->assertViewIs('dashboard');
    }

    public function test_ecosystem_auth_rejects_missing_token(): void
    {
        $this->get('/auth/ecosystem')->assertStatus(403);
    }

    public function test_billing_plan_can_be_created(): void
    {
        $plan = BillingPlan::create([
            'name'          => 'Pro',
            'slug'          => 'pro',
            'price_monthly' => 299.00,
            'price_annual'  => 2990.00,
        ]);
        $this->assertDatabaseHas('billing_plans', ['slug' => 'pro']);
        $this->assertFalse($plan->isFree());
    }

    public function test_free_plan_detection(): void
    {
        $plan = BillingPlan::create([
            'name'          => 'Free',
            'slug'          => 'free',
            'price_monthly' => 0,
            'price_annual'  => 0,
        ]);
        $this->assertTrue($plan->isFree());
    }

    public function test_subscription_belongs_to_team(): void
    {
        $plan = BillingPlan::create([
            'name'          => 'Starter',
            'slug'          => 'starter',
            'price_monthly' => 99,
        ]);
        $sub = BillingSubscription::create([
            'team_id'       => $this->team->id,
            'plan_id'       => $plan->id,
            'status'        => 'active',
            'billing_cycle' => 'monthly',
        ]);
        $this->assertTrue($sub->team->is($this->team));
        $this->assertTrue($sub->isActive());
    }

    public function test_invoice_overdue_detection(): void
    {
        $invoice = BillingInvoice::create([
            'team_id'        => $this->team->id,
            'invoice_number' => 'INV-001',
            'status'         => 'open',
            'total'          => 500,
            'due_date'       => now()->subDays(5),
        ]);
        $this->assertTrue($invoice->isOverdue());
        $this->assertFalse($invoice->isPaid());
    }

    public function test_paid_invoice_not_overdue(): void
    {
        $invoice = BillingInvoice::create([
            'team_id'        => $this->team->id,
            'invoice_number' => 'INV-002',
            'status'         => 'paid',
            'total'          => 299,
            'paid_at'        => now(),
        ]);
        $this->assertTrue($invoice->isPaid());
        $this->assertFalse($invoice->isOverdue());
    }

    public function test_billing_alert_active_status(): void
    {
        $alert = BillingAlert::create([
            'team_id'          => $this->team->id,
            'type'             => 'budget_threshold',
            'threshold_metric' => 'monthly_spend',
            'threshold_value'  => 1000,
            'status'           => 'active',
        ]);
        $this->assertTrue($alert->isActive());
    }

    public function test_team_has_billing_relationships(): void
    {
        BillingInvoice::create([
            'team_id'        => $this->team->id,
            'invoice_number' => 'INV-003',
            'status'         => 'paid',
            'total'          => 100,
        ]);
        $this->assertCount(1, $this->team->invoices);
    }

    public function test_authenticated_user_with_no_team_is_redirected_to_team_creation(): void
    {
        // A user with no current team (e.g. removed from their last team, or
        // never assigned one) has a genuinely null Auth::user()->currentTeam
        // here: there is no EnsureTeamContext-style middleware in this app
        // that forces a team to exist before /dashboard renders. Regression
        // test for BillingOverview/UsageDashboard's mount() null-team guard.
        $user = User::factory()->create(['current_team_id' => null]);

        $response = $this->actingAs($user)->get('/dashboard');

        // BillingOverview::mount() (and UsageDashboard::mount()) abort the
        // whole request with a redirect via Livewire's SupportRedirects,
        // even though they're nested components rendered inside the
        // /dashboard route closure's response, not the route itself.
        $response->assertRedirect(route('teams.create'));
    }

    public function test_dashboard_returns_correct_counts(): void
    {
        BillingInvoice::create([
            'team_id'        => $this->team->id,
            'invoice_number' => 'INV-004',
            'status'         => 'open',
            'total'          => 50,
        ]);
        BillingInvoice::create([
            'team_id'        => $this->team->id,
            'invoice_number' => 'INV-005',
            'status'         => 'paid',
            'total'          => 200,
            'paid_at'        => now(),
        ]);

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewHas('openInvoices', 1)
            ->assertViewHas('planName', 'No Plan');
    }
}
