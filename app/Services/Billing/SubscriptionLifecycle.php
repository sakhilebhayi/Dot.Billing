<?php

namespace App\Services\Billing;

use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves subscriptions through time: ends expired trials, rolls ended periods forward (applying a plan
 * change queued for renewal), ends subscriptions scheduled to cancel, and keeps past_due honest.
 *
 * Idempotent: running it twice in a row changes nothing the second time. It never charges anybody and
 * never cancels a subscription for non-payment; a past-due subscription stays past due until a person
 * acts (see the dunning queue) or the invoice is paid.
 */
class SubscriptionLifecycle
{
    /** Stops a corrupt row from looping: more than this many periods in one run is not a catch-up. */
    private const MAX_PERIODS_PER_RUN = 36;

    public function __construct(private InvoiceGenerator $invoices, private AuditLog $audit, private SeatChange $seats) {}

    /**
     * @return array{advanced: int, ended: int, past_due: int, recovered: int, failed: int}
     */
    public function run(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $stats = ['advanced' => 0, 'ended' => 0, 'past_due' => 0, 'recovered' => 0, 'failed' => 0];

        $due = BillingSubscription::withoutGlobalScope('team')
            ->whereIn('status', BillingSubscription::LIVE_STATUSES)
            ->where(function ($q) use ($now) {
                $q->where('current_period_end', '<=', $now)
                    ->orWhere(fn ($t) => $t->where('status', 'trialing')->where('trial_ends_at', '<=', $now));
            })
            ->pluck('id');

        foreach ($due as $id) {
            try {
                DB::transaction(function () use ($id, $now, &$stats) {
                    $this->advanceOne($id, $now, $stats);
                });
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::error('Subscription advance failed.', ['subscription_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        $this->syncPastDue($now, $stats);

        return $stats;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function advanceOne(int $id, CarbonInterface $now, array &$stats): void
    {
        for ($i = 0; $i < self::MAX_PERIODS_PER_RUN; $i++) {
            $sub = BillingSubscription::withoutGlobalScope('team')->with('plan')->lockForUpdate()->find($id);

            if (! $sub || ! in_array($sub->status, BillingSubscription::LIVE_STATUSES, true)) {
                return;
            }

            if ($sub->status === 'trialing') {
                $trialEnd = $sub->trial_ends_at ?? $sub->current_period_end;

                if ($trialEnd === null || $trialEnd->gt($now)) {
                    return;
                }

                if ($sub->cancel_at_period_end) {
                    $this->end($sub, $trialEnd, $now);
                    $stats['ended']++;

                    return;
                }

                $sub->forceFill([
                    'status' => 'active',
                    'current_period_start' => $trialEnd,
                    'current_period_end' => SubscriptionService::periodEnd($trialEnd, $sub->billing_cycle),
                ])->save();
                $this->audit->record('subscription.trial_ended', $sub->team_id, null, $sub);
                $this->invoices->generateForCurrentPeriod($sub, $now);
                $stats['advanced']++;

                continue;
            }

            if ($sub->current_period_end === null || $sub->current_period_end->gt($now)) {
                return;
            }

            if ($sub->cancel_at_period_end) {
                $this->end($sub, $sub->current_period_end, $now);
                $stats['ended']++;

                return;
            }

            $this->roll($sub);
            $this->invoices->generateForCurrentPeriod($sub->fresh('plan'), $now);
            $stats['advanced']++;
        }
    }

    private function roll(BillingSubscription $sub): void
    {
        $start = $sub->current_period_end;
        $changes = [];

        if ($sub->pending_plan_id !== null && BillingPlan::whereKey($sub->pending_plan_id)->exists()) {
            $changes['plan_id'] = $sub->pending_plan_id;
            $changes['billing_cycle'] = $sub->pending_billing_cycle ?? $sub->billing_cycle;
        }

        $cycle = $changes['billing_cycle'] ?? $sub->billing_cycle;
        $plan = isset($changes['plan_id']) ? BillingPlan::findOrFail($changes['plan_id']) : $sub->plan;

        // Scheduled seat reductions take effect now; seats are dropped if the plan no longer sells them.
        $changes += $this->seats->onRenewal($sub, $plan, $cycle);

        $sub->forceFill($changes + [
            'pending_plan_id' => null,
            'pending_billing_cycle' => null,
            'current_period_start' => $start,
            'current_period_end' => SubscriptionService::periodEnd($start, $cycle),
        ])->save();

        $this->audit->record('subscription.renewed', $sub->team_id, null, $sub, [
            'period_start' => $start->toIso8601String(),
            'plan_changed' => isset($changes['plan_id']),
        ]);
    }

    private function end(BillingSubscription $sub, CarbonInterface $endedAt, CarbonInterface $now): void
    {
        $sub->forceFill([
            'status' => 'canceled',
            'canceled_at' => $now,
            'ended_at' => $endedAt,
            'cancel_at_period_end' => false,
            'pending_plan_id' => null,
            'pending_billing_cycle' => null,
        ])->save();

        $this->audit->record('subscription.ended', $sub->team_id, null, $sub, ['ended_at' => $endedAt->toIso8601String()]);
    }

    /**
     * past_due means exactly one thing: the team has an open invoice past its due date (and past any grace
     * a human granted). Anything else is active.
     *
     * @param  array<string, int>  $stats
     */
    private function syncPastDue(CarbonInterface $now, array &$stats): void
    {
        BillingSubscription::withoutGlobalScope('team')
            ->where('status', 'active')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('billing_invoices')
                ->whereColumn('billing_invoices.team_id', 'billing_subscriptions.team_id')
                ->where('billing_invoices.status', 'open')
                ->where('billing_invoices.due_date', '<', $now)
                ->where(fn ($g) => $g->whereNull('billing_invoices.grace_until')->orWhere('billing_invoices.grace_until', '<=', $now)))
            ->get()
            ->each(function (BillingSubscription $sub) use (&$stats) {
                $sub->forceFill(['status' => 'past_due'])->save();
                $this->audit->record('subscription.past_due', $sub->team_id, null, $sub);
                $stats['past_due']++;
            });

        BillingSubscription::withoutGlobalScope('team')
            ->where('status', 'past_due')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('billing_invoices')
                ->whereColumn('billing_invoices.team_id', 'billing_subscriptions.team_id')
                ->where('billing_invoices.status', 'open')
                ->where('billing_invoices.due_date', '<', $now)
                ->where(fn ($g) => $g->whereNull('billing_invoices.grace_until')->orWhere('billing_invoices.grace_until', '<=', $now)))
            ->get()
            ->each(function (BillingSubscription $sub) use (&$stats) {
                $sub->forceFill(['status' => 'active'])->save();
                $this->audit->record('subscription.recovered', $sub->team_id, null, $sub);
                $stats['recovered']++;
            });
    }
}
