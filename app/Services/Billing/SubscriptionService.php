<?php

namespace App\Services\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only place a subscription changes on a person's say-so: subscribe, change plan, cancel at period
 * end, resume, cancel now. Authorization is the caller's job (the manage-billing gate for team users,
 * the dunning review policy for operators); this class works on any team whoever is signed in, so it
 * always bypasses the ambient team scope and names the team it means.
 *
 * Money rules: a plan change on a running subscription is queued for the next renewal, so no
 * proration is ever needed; nothing is charged here, only invoiced (see InvoiceGenerator).
 */
class SubscriptionService
{
    public const CYCLES = ['monthly', 'annual'];

    public function __construct(private InvoiceGenerator $invoices, private AuditLog $audit) {}

    public static function periodEnd(CarbonInterface $start, string $cycle): CarbonInterface
    {
        return $cycle === 'annual' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();
    }

    public function subscribe(Team $team, BillingPlan $plan, string $cycle = 'monthly', ?User $actor = null): BillingSubscription
    {
        $this->assertCycle($cycle);
        $this->assertSubscribable($plan);

        try {
            $subscription = DB::transaction(function () use ($team, $plan, $cycle, $actor) {
                // Serialise concurrent subscribes for one team; the partial unique index is the backstop.
                Team::whereKey($team->id)->lockForUpdate()->first();

                $hasLive = BillingSubscription::withoutGlobalScope('team')
                    ->where('team_id', $team->id)->whereIn('status', BillingSubscription::LIVE_STATUSES)->exists();

                if ($hasLive) {
                    throw BillingRuleViolation::because('already_subscribed', 'This team already has a subscription. Change or cancel it instead.');
                }

                $hadTrial = BillingSubscription::withoutGlobalScope('team')
                    ->where('team_id', $team->id)->whereNotNull('trial_ends_at')->exists();

                $now = now();
                $trial = $plan->trial_days > 0 && ! $hadTrial;
                $trialEnds = $trial ? $now->copy()->addDays($plan->trial_days) : null;

                $subscription = BillingSubscription::create([
                    'team_id' => $team->id,
                    'plan_id' => $plan->id,
                    'status' => $trial ? 'trialing' : 'active',
                    'billing_cycle' => $cycle,
                    'trial_ends_at' => $trialEnds,
                    'current_period_start' => $now,
                    'current_period_end' => $trial ? $trialEnds : self::periodEnd($now, $cycle),
                ]);

                $this->audit->record('subscription.created', $team, $actor, $subscription, [
                    'plan' => $plan->slug, 'cycle' => $cycle, 'trial' => $trial,
                ]);

                $this->invoices->generateForCurrentPeriod($subscription->load('plan'), $now);

                return $subscription;
            });
        } catch (UniqueConstraintViolationException) {
            throw BillingRuleViolation::because('already_subscribed', 'This team already has a subscription. Change or cancel it instead.');
        }

        return $subscription->fresh(['plan']);
    }

    public function changePlan(BillingSubscription $subscription, BillingPlan $plan, ?string $cycle = null, ?User $actor = null): BillingSubscription
    {
        return DB::transaction(function () use ($subscription, $plan, $cycle, $actor) {
            $subscription = $this->lockLive($subscription);
            $cycle ??= $subscription->billing_cycle;
            $this->assertCycle($cycle);
            $this->assertSubscribable($plan);

            if ($subscription->status === 'trialing') {
                $subscription->forceFill([
                    'plan_id' => $plan->id, 'billing_cycle' => $cycle, 'pending_plan_id' => null, 'pending_billing_cycle' => null,
                ])->save();
                $this->audit->record('subscription.plan_changed', $subscription->team_id, $actor, $subscription, ['plan' => $plan->slug, 'cycle' => $cycle]);

                return $subscription;
            }

            if ($plan->id === $subscription->plan_id && $cycle === $subscription->billing_cycle) {
                $subscription->forceFill(['pending_plan_id' => null, 'pending_billing_cycle' => null])->save();

                return $subscription;
            }

            $subscription->forceFill(['pending_plan_id' => $plan->id, 'pending_billing_cycle' => $cycle])->save();
            $this->audit->record('subscription.plan_change_scheduled', $subscription->team_id, $actor, $subscription, [
                'plan' => $plan->slug, 'cycle' => $cycle, 'effective' => optional($subscription->current_period_end)->toIso8601String(),
            ]);

            return $subscription;
        });
    }

    public function cancelAtPeriodEnd(BillingSubscription $subscription, ?User $actor = null): BillingSubscription
    {
        return DB::transaction(function () use ($subscription, $actor) {
            $subscription = $this->lockLive($subscription);

            if (! $subscription->cancel_at_period_end) {
                $subscription->forceFill(['cancel_at_period_end' => true])->save();
                $this->audit->record('subscription.cancel_scheduled', $subscription->team_id, $actor, $subscription, [
                    'ends' => optional($subscription->current_period_end)->toIso8601String(),
                ]);
            }

            return $subscription;
        });
    }

    public function resume(BillingSubscription $subscription, ?User $actor = null): BillingSubscription
    {
        return DB::transaction(function () use ($subscription, $actor) {
            $subscription = $this->lockLive($subscription);

            if (! $subscription->cancel_at_period_end) {
                throw BillingRuleViolation::because('invalid_state', 'This subscription is not scheduled to cancel.');
            }

            $subscription->forceFill(['cancel_at_period_end' => false])->save();
            $this->audit->record('subscription.resumed', $subscription->team_id, $actor, $subscription);

            return $subscription;
        });
    }

    /**
     * End the subscription now. Used by a human reviewing a dunning case; never called by a scheduler.
     */
    public function cancelNow(BillingSubscription $subscription, ?User $actor, string $reason): BillingSubscription
    {
        return DB::transaction(function () use ($subscription, $actor, $reason) {
            $subscription = $this->lockLive($subscription);

            $subscription->forceFill([
                'status' => 'canceled',
                'canceled_at' => now(),
                'ended_at' => now(),
                'cancel_at_period_end' => false,
                'pending_plan_id' => null,
                'pending_billing_cycle' => null,
            ])->save();

            $this->audit->record('subscription.canceled', $subscription->team_id, $actor, $subscription, ['reason' => $reason]);

            return $subscription;
        });
    }

    private function lockLive(BillingSubscription $subscription): BillingSubscription
    {
        $locked = BillingSubscription::withoutGlobalScope('team')->lockForUpdate()->findOrFail($subscription->id);

        if (! in_array($locked->status, BillingSubscription::LIVE_STATUSES, true)) {
            throw BillingRuleViolation::because('invalid_state', 'This subscription has already ended.');
        }

        return $locked;
    }

    private function assertCycle(string $cycle): void
    {
        if (! in_array($cycle, self::CYCLES, true)) {
            throw BillingRuleViolation::because('invalid_cycle', 'Billing cycle must be monthly or annual.');
        }
    }

    private function assertSubscribable(BillingPlan $plan): void
    {
        if (! $plan->is_active) {
            throw BillingRuleViolation::because('plan_archived', 'That plan is archived and cannot be chosen.');
        }
    }
}
