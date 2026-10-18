<?php

namespace Tests\Feature\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingAuditEvent;
use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->service = app(SubscriptionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function violation(callable $fn): string
    {
        try {
            $fn();
        } catch (BillingRuleViolation $e) {
            return $e->reason;
        }

        $this->fail('Expected a BillingRuleViolation.');
    }

    public function test_subscribing_starts_an_active_subscription_with_one_monthly_period(): void
    {
        $owner = $this->makeOwner();
        $plan = $this->makePlan();

        $sub = $this->service->subscribe($owner->currentTeam, $plan, 'monthly', $owner);

        $this->assertSame('active', $sub->status);
        $this->assertSame('monthly', $sub->billing_cycle);
        $this->assertTrue($sub->current_period_start->equalTo('2026-11-05 09:00:00'));
        $this->assertTrue($sub->current_period_end->equalTo('2026-12-05 09:00:00'));
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertNull($sub->trial_ends_at);
    }

    public function test_an_annual_subscription_runs_for_a_year(): void
    {
        $owner = $this->makeOwner();

        $sub = $this->service->subscribe($owner->currentTeam, $this->makePlan(), 'annual', $owner);

        $this->assertTrue($sub->current_period_end->equalTo('2027-11-05 09:00:00'));
    }

    public function test_an_unknown_billing_cycle_is_refused(): void
    {
        $owner = $this->makeOwner();

        $this->assertSame('invalid_cycle', $this->violation(fn () => $this->service->subscribe($owner->currentTeam, $this->makePlan(), 'weekly', $owner)));
        $this->assertSame(0, BillingSubscription::withoutGlobalScope('team')->count());
    }

    public function test_an_archived_plan_cannot_be_newly_subscribed(): void
    {
        $owner = $this->makeOwner();
        $plan = $this->makePlan(['is_active' => false]);

        $this->assertSame('plan_archived', $this->violation(fn () => $this->service->subscribe($owner->currentTeam, $plan, 'monthly', $owner)));
        $this->assertSame(0, BillingSubscription::withoutGlobalScope('team')->count());
    }

    public function test_a_team_cannot_hold_two_live_subscriptions(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $this->service->subscribe($team, $this->makePlan(), 'monthly', $owner);

        $this->assertSame('already_subscribed', $this->violation(fn () => $this->service->subscribe($team, $this->makePlan(), 'monthly', $owner)));
        $this->assertSame(1, BillingSubscription::withoutGlobalScope('team')->where('team_id', $team->id)->count());
    }

    public function test_past_due_still_counts_as_the_teams_live_subscription(): void
    {
        $owner = $this->makeOwner();
        $this->makeSubscription($owner->currentTeam, $this->makePlan(), ['status' => 'past_due']);

        $this->assertSame('already_subscribed', $this->violation(fn () => $this->service->subscribe($owner->currentTeam, $this->makePlan(), 'monthly', $owner)));
    }

    public function test_the_database_itself_refuses_a_second_live_subscription(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            $this->markTestSkipped('Partial unique indexes are only created on sqlite and pgsql; other engines rely on the service check.');
        }

        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $this->makeSubscription($team, $this->makePlan());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeSubscription($team, $this->makePlan(), ['status' => 'trialing']);
    }

    public function test_canceled_subscriptions_do_not_block_a_new_one(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $this->makeSubscription($team, $this->makePlan(), ['status' => 'canceled', 'canceled_at' => now()->subMonth()]);
        $this->makeSubscription($team, $this->makePlan(), ['status' => 'expired']);

        $sub = $this->service->subscribe($team, $this->makePlan(), 'monthly', $owner);

        $this->assertSame('active', $sub->status);
    }

    public function test_a_plan_with_trial_days_starts_a_trial_and_bills_nothing(): void
    {
        $owner = $this->makeOwner();
        $plan = $this->makePlan(['trial_days' => 14]);

        $sub = $this->service->subscribe($owner->currentTeam, $plan, 'monthly', $owner);

        $this->assertSame('trialing', $sub->status);
        $this->assertTrue($sub->trial_ends_at->equalTo('2026-11-19 09:00:00'));
        $this->assertTrue($sub->current_period_end->equalTo('2026-11-19 09:00:00'));
        $this->assertSame(0, $sub->invoices()->withoutGlobalScope('team')->count());
    }

    public function test_a_team_only_gets_one_trial(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $plan = $this->makePlan(['trial_days' => 14]);
        $first = $this->service->subscribe($team, $plan, 'monthly', $owner);
        $this->service->cancelNow($first, $owner, 'changed my mind');

        $second = $this->service->subscribe($team, $plan, 'monthly', $owner);

        $this->assertSame('active', $second->status);
        $this->assertNull($second->trial_ends_at);
    }

    public function test_every_change_leaves_an_audit_row_naming_who_did_it(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $sub = $this->service->subscribe($team, $this->makePlan(), 'monthly', $owner);
        $this->service->cancelAtPeriodEnd($sub, $owner);
        $this->service->resume($sub->fresh(), $owner);

        $actions = BillingAuditEvent::withoutGlobalScope('team')->where('team_id', $team->id)->where('action', 'like', 'subscription.%')->orderBy('id')->pluck('action')->all();

        $this->assertSame(['subscription.created', 'subscription.cancel_scheduled', 'subscription.resumed'], $actions);
        $this->assertSame($owner->id, BillingAuditEvent::withoutGlobalScope('team')->where('action', 'subscription.created')->value('actor_id'));
    }

    public function test_changing_plan_during_a_trial_takes_effect_at_once(): void
    {
        $owner = $this->makeOwner();
        $trial = $this->makePlan(['trial_days' => 14]);
        $bigger = $this->makePlan(['name' => 'Bigger']);
        $sub = $this->service->subscribe($owner->currentTeam, $trial, 'monthly', $owner);

        $sub = $this->service->changePlan($sub, $bigger, 'annual', $owner);

        $this->assertSame($bigger->id, $sub->plan_id);
        $this->assertSame('annual', $sub->billing_cycle);
        $this->assertNull($sub->pending_plan_id);
    }

    public function test_changing_plan_on_an_active_subscription_is_scheduled_for_the_next_period(): void
    {
        $owner = $this->makeOwner();
        $current = $this->makePlan();
        $bigger = $this->makePlan();
        $sub = $this->service->subscribe($owner->currentTeam, $current, 'monthly', $owner);

        $sub = $this->service->changePlan($sub, $bigger, 'annual', $owner);

        $this->assertSame($current->id, $sub->plan_id, 'entitlements and billing stay on the current plan until renewal');
        $this->assertSame($bigger->id, $sub->pending_plan_id);
        $this->assertSame('annual', $sub->pending_billing_cycle);
    }

    public function test_choosing_the_current_plan_again_cancels_a_pending_change(): void
    {
        $owner = $this->makeOwner();
        $current = $this->makePlan();
        $sub = $this->service->subscribe($owner->currentTeam, $current, 'monthly', $owner);
        $sub = $this->service->changePlan($sub, $this->makePlan(), 'monthly', $owner);

        $sub = $this->service->changePlan($sub, $current, 'monthly', $owner);

        $this->assertNull($sub->pending_plan_id);
        $this->assertNull($sub->pending_billing_cycle);
    }

    public function test_changing_to_an_archived_plan_is_refused(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->service->subscribe($owner->currentTeam, $this->makePlan(), 'monthly', $owner);
        $archived = $this->makePlan(['is_active' => false]);

        $this->assertSame('plan_archived', $this->violation(fn () => $this->service->changePlan($sub, $archived, 'monthly', $owner)));
    }

    public function test_cancel_at_period_end_keeps_the_subscription_running_until_then(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->service->subscribe($owner->currentTeam, $this->makePlan(), 'monthly', $owner);

        $sub = $this->service->cancelAtPeriodEnd($sub, $owner);

        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertSame('active', $sub->status);
        $this->assertNull($sub->canceled_at);
    }

    public function test_resume_undoes_a_scheduled_cancellation(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->service->subscribe($owner->currentTeam, $this->makePlan(), 'monthly', $owner);
        $sub = $this->service->cancelAtPeriodEnd($sub, $owner);

        $sub = $this->service->resume($sub, $owner);

        $this->assertFalse($sub->cancel_at_period_end);
    }

    public function test_resume_has_nothing_to_undo_on_a_healthy_or_ended_subscription(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->service->subscribe($owner->currentTeam, $this->makePlan(), 'monthly', $owner);

        $this->assertSame('invalid_state', $this->violation(fn () => $this->service->resume($sub, $owner)));

        $ended = $this->service->cancelNow($sub, $owner, 'test');
        $this->assertSame('invalid_state', $this->violation(fn () => $this->service->resume($ended, $owner)));
        $this->assertSame('invalid_state', $this->violation(fn () => $this->service->cancelAtPeriodEnd($ended, $owner)));
    }

    public function test_cancel_now_ends_the_subscription_immediately_and_records_why(): void
    {
        $owner = $this->makeOwner();
        $operator = $this->makeOperator();
        $sub = $this->service->subscribe($owner->currentTeam, $this->makePlan(), 'monthly', $owner);

        $sub = $this->service->cancelNow($sub, $operator, 'Invoice overdue, no response');

        $this->assertSame('canceled', $sub->status);
        $this->assertTrue($sub->canceled_at->equalTo(now()));
        $this->assertTrue($sub->ended_at->equalTo(now()));

        $audit = BillingAuditEvent::withoutGlobalScope('team')->where('action', 'subscription.canceled')->firstOrFail();
        $this->assertSame($operator->id, $audit->actor_id);
        $this->assertSame('Invoice overdue, no response', $audit->meta['reason']);
    }

    public function test_the_service_works_for_any_team_whatever_user_is_signed_in(): void
    {
        $owner = $this->makeOwner();
        $otherTeamUser = User::factory()->withPersonalTeam()->create();
        $this->actingAs($otherTeamUser);

        $sub = $this->service->subscribe($owner->currentTeam, $this->makePlan(), 'monthly', $owner);

        $this->assertSame($owner->currentTeam->id, $sub->team_id);
    }
}
