<?php

namespace App\Livewire\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingSubscription;
use App\Models\DunningCase;
use App\Services\Billing\AuditLog;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The platform operator's review queue. Everything here is a person's decision; nothing is automatic.
 *
 *  Extend  grants a real grace period: the case moves to "grace" with an end date and the invoice stops
 *          counting as overdue until then. The invoice's due date is never rewritten. The scanner
 *          reopens the case only after the grace ends and the invoice is still unpaid.
 *  Cancel  ends the ONE subscription this case is tied to (through its invoice), and says so if it
 *          cannot tell which that is, rather than guessing.
 *  Dismiss closes the case and changes nothing else.
 */
class DunningQueue extends Component
{
    public ?string $error = null;

    /** @return Collection<int, DunningCase> */
    #[Computed]
    public function cases(): Collection
    {
        return DunningCase::whereIn('status', DunningCase::WORKING_STATUSES)
            ->with(['team', 'invoice', 'payment'])
            ->orderBy('created_at')
            ->get();
    }

    public function extend(int $id, int $days = 7): void
    {
        $case = DunningCase::findOrFail($id);
        Gate::authorize('review', $case);
        $this->error = null;

        $days = max(1, min(30, $days));
        $until = now()->addDays($days);
        $audit = app(AuditLog::class);

        DB::transaction(function () use ($case, $until, $days, $audit) {
            $case->forceFill([
                'status' => 'grace',
                'grace_until' => $until,
                'resolved_at' => null,
                'resolved_by' => null,
            ])->save();

            if ($case->invoice?->status === 'open') {
                $case->invoice->forceFill(['grace_until' => $until])->save();
            }

            $audit->record('dunning.grace_granted', $case->team_id, auth()->user(), $case->invoice, [
                'case_id' => $case->id, 'days' => $days, 'grace_until' => $until->toIso8601String(),
            ]);
        });

        unset($this->cases);
    }

    public function cancelSubscription(int $id): void
    {
        $case = DunningCase::findOrFail($id);
        Gate::authorize('review', $case);
        $this->error = null;

        $subscription = $this->subscriptionFor($case);

        if ($subscription === null) {
            $this->error = 'This case has no running subscription to cancel, so nothing was cancelled. Review it by hand.';

            return;
        }

        try {
            DB::transaction(function () use ($case, $subscription) {
                app(SubscriptionService::class)->cancelNow($subscription, auth()->user(), "Dunning case #{$case->id} ({$case->reason})");

                $case->update(['status' => 'canceled', 'grace_until' => null, 'resolved_at' => now(), 'resolved_by' => auth()->id()]);
            });
        } catch (BillingRuleViolation $e) {
            $this->error = $e->getMessage();
        }

        unset($this->cases);
    }

    public function dismiss(int $id): void
    {
        $case = DunningCase::findOrFail($id);
        Gate::authorize('review', $case);
        $this->error = null;

        $case->update(['status' => 'dismissed', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);
        app(AuditLog::class)->record('dunning.dismissed', $case->team_id, auth()->user(), $case->invoice, ['case_id' => $case->id]);

        unset($this->cases);
    }

    /**
     * The subscription this case is about: the one its invoice was raised for. A legacy invoice with no
     * link falls back to the team's only live subscription; with none or ambiguity there is no answer.
     */
    private function subscriptionFor(DunningCase $case): ?BillingSubscription
    {
        // BillingSubscription carries HasTeamScope, which fails closed for a platform_operator
        // (authenticated, deliberately teamless), hence the explicit bypass plus the team check.
        $query = BillingSubscription::withoutGlobalScope('team')
            ->where('team_id', $case->team_id)
            ->whereIn('status', BillingSubscription::LIVE_STATUSES);

        if ($case->invoice?->subscription_id) {
            return $query->whereKey($case->invoice->subscription_id)->first();
        }

        $live = $query->get();

        return $live->count() === 1 ? $live->first() : null;
    }

    public function render(): View
    {
        return view('livewire.billing.dunning-queue');
    }
}
