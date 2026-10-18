<?php

namespace Tests\Feature\Billing;

use App\Livewire\Billing\BillingOverview;
use App\Models\BillingSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class SubscriptionPanelTest extends TestCase
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

    private function live(int $teamId): ?BillingSubscription
    {
        return BillingSubscription::withoutGlobalScope('team')->where('team_id', $teamId)->whereIn('status', BillingSubscription::LIVE_STATUSES)->first();
    }

    public function test_a_team_with_no_subscription_is_offered_only_the_active_plans(): void
    {
        $owner = $this->makeOwner();
        $this->makePlan(['name' => 'Offered Meridian Plan']);
        $this->makePlan(['name' => 'Archived Quartz Plan', 'is_active' => false]);

        Livewire::actingAs($owner)->test(BillingOverview::class)
            ->assertSee('Choose a plan')
            ->assertSee('Offered Meridian Plan')
            ->assertDontSee('Archived Quartz Plan');
    }

    public function test_the_owner_can_subscribe_and_the_first_invoice_appears(): void
    {
        $owner = $this->makeOwner();
        $plan = $this->makePlan(['name' => 'Meridian Standard', 'price_monthly_cents' => 45000]);

        Livewire::actingAs($owner)->test(BillingOverview::class)
            ->call('subscribe', $plan->id, 'monthly')
            ->assertSee('Meridian Standard')
            ->assertSee('Active');

        $sub = $this->live($owner->currentTeam->id);
        $this->assertSame($plan->id, $sub->plan_id);
        $this->assertSame(1, $sub->invoices()->withoutGlobalScope('team')->count());
    }

    public function test_an_admin_can_subscribe_but_an_editor_and_a_viewer_cannot(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $plan = $this->makePlan();

        foreach (['editor', 'viewer'] as $role) {
            Livewire::actingAs($this->makeMember($team, $role))->test(BillingOverview::class)
                ->call('subscribe', $plan->id, 'monthly')
                ->assertForbidden();
        }
        $this->assertNull($this->live($team->id));

        Livewire::actingAs($this->makeMember($team, 'admin'))->test(BillingOverview::class)
            ->call('subscribe', $plan->id, 'monthly')
            ->assertOk();
        $this->assertNotNull($this->live($team->id));
    }

    public function test_a_viewer_sees_the_state_but_none_of_the_buttons(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $this->makeSubscription($team, $this->makePlan(['name' => 'Meridian Standard']));
        $this->makePlan(['name' => 'Another Choosable Plan']);

        Livewire::actingAs($this->makeMember($team, 'viewer'))->test(BillingOverview::class)
            ->assertSee('Meridian Standard')
            ->assertDontSee('Cancel subscription')
            ->assertDontSee('Another Choosable Plan')
            ->assertSee('Only team owners and admins can change billing.');
    }

    public function test_an_archived_plan_cannot_be_chosen_even_with_a_crafted_request(): void
    {
        $owner = $this->makeOwner();
        $archived = $this->makePlan(['is_active' => false]);

        Livewire::actingAs($owner)->test(BillingOverview::class)
            ->call('subscribe', $archived->id, 'monthly')
            ->assertSee('That plan is archived and cannot be chosen.');

        $this->assertNull($this->live($owner->currentTeam->id));
    }

    public function test_an_active_subscription_shows_its_renewal_and_can_be_cancelled_then_resumed(): void
    {
        $owner = $this->makeOwner();
        $this->makeSubscription($owner->currentTeam, $this->makePlan(['name' => 'Meridian Standard']), [
            'current_period_start' => '2026-10-20 09:00:00', 'current_period_end' => '2026-11-20 09:00:00',
        ]);

        $component = Livewire::actingAs($owner)->test(BillingOverview::class)
            ->assertSee('Renews on 20 Nov 2026')
            ->call('cancelAtPeriodEnd')
            ->assertSee('Your subscription ends on 20 Nov 2026')
            ->assertSee('Resume subscription');

        $this->assertTrue($this->live($owner->currentTeam->id)->cancel_at_period_end);

        $component->call('resume')->assertSee('Renews on 20 Nov 2026');
        $this->assertFalse($this->live($owner->currentTeam->id)->fresh()->cancel_at_period_end);
    }

    public function test_a_trial_says_when_it_ends_and_that_nothing_is_charged_automatically(): void
    {
        $owner = $this->makeOwner();
        $this->makeSubscription($owner->currentTeam, $this->makePlan(), [
            'status' => 'trialing', 'trial_ends_at' => '2026-11-19 09:00:00', 'current_period_end' => '2026-11-19 09:00:00',
        ]);

        Livewire::actingAs($owner)->test(BillingOverview::class)
            ->assertSee('Trial ends on 19 Nov 2026')
            ->assertSee('You are not charged automatically');
    }

    public function test_a_past_due_subscription_points_at_the_overdue_invoice(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $sub = $this->makeSubscription($team, $this->makePlan(), ['status' => 'past_due']);
        $this->makeInvoice($team, ['subscription_id' => $sub->id, 'invoice_number' => 'OVERDUE-DISTINCT-7731', 'due_date' => now()->subDays(3)]);

        Livewire::actingAs($owner)->test(BillingOverview::class)
            ->assertSee('Payment overdue')
            ->assertSee('OVERDUE-DISTINCT-7731');
    }

    public function test_a_canceled_subscription_says_so_and_offers_plans_again(): void
    {
        $owner = $this->makeOwner();
        $this->makeSubscription($owner->currentTeam, $this->makePlan(['name' => 'Former Plan Name']), [
            'status' => 'canceled', 'canceled_at' => '2026-10-01 09:00:00', 'ended_at' => '2026-10-01 09:00:00',
        ]);
        $this->makePlan(['name' => 'Fresh Start Plan']);

        Livewire::actingAs($owner)->test(BillingOverview::class)
            ->assertSee('Canceled on 1 Oct 2026')
            ->assertSee('Choose a plan')
            ->assertSee('Fresh Start Plan');
    }

    public function test_a_plan_change_is_shown_as_scheduled_and_can_be_withdrawn(): void
    {
        $owner = $this->makeOwner();
        $current = $this->makePlan(['name' => 'Current Meridian']);
        $next = $this->makePlan(['name' => 'Scheduled Zenith']);
        $this->makeSubscription($owner->currentTeam, $current, [
            'current_period_end' => '2026-11-20 09:00:00',
        ]);

        Livewire::actingAs($owner)->test(BillingOverview::class)
            ->call('changePlan', $next->id, 'monthly')
            ->assertSee('Switching to Scheduled Zenith (monthly) on 20 Nov 2026')
            ->call('keepCurrentPlan')
            ->assertDontSee('Switching to Scheduled Zenith');

        $this->assertNull($this->live($owner->currentTeam->id)->pending_plan_id);
    }

    public function test_a_subscription_of_another_team_is_never_shown_or_changed(): void
    {
        $owner = $this->makeOwner();
        $other = $this->makeOwner();
        $otherSub = $this->makeSubscription($other->currentTeam, $this->makePlan(['name' => 'Other Teams Secret Plan', 'is_active' => false]));

        Livewire::actingAs($owner)->test(BillingOverview::class)
            ->assertDontSee('Other Teams Secret Plan')
            ->call('cancelAtPeriodEnd');

        $this->assertFalse($otherSub->fresh()->cancel_at_period_end);
    }
}
