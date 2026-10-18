<?php

namespace App\Livewire\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Team;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The team's subscription: its state in words, what happens next, and (for owners and admins only)
 * the actions. Every action authorizes manage-billing against the CURRENT team and then acts on that
 * team's own subscription, so a crafted request cannot reach another team's.
 */
class BillingOverview extends Component
{
    public ?string $notice = null;

    /**
     * A user removed from their last team (or never assigned one) has a
     * genuinely null `currentTeam` here — nothing in this app's routing
     * forces a team to exist before this component renders (there is no
     * EnsureTeamContext-style middleware; HasTeamScope only fails closed
     * on queries, it doesn't stop a bare `currentTeam->subscription()` call
     * from dereferencing null). Send them to team creation instead of
     * crashing below.
     */
    public function mount(): void
    {
        if (! $this->resolveCurrentTeam()) {
            $this->redirect(route('teams.create'), navigate: true);
        }
    }

    private function resolveCurrentTeam(): ?Team
    {
        return Auth::user()?->currentTeam;
    }

    /** The team, after proving the user may manage its billing. */
    private function managedTeam(): Team
    {
        $team = $this->resolveCurrentTeam();
        abort_unless($team, 403, 'No active team selected.');
        Gate::authorize('manage-billing', $team);

        return $team;
    }

    #[Computed]
    public function canManage(): bool
    {
        $team = $this->resolveCurrentTeam();

        return $team !== null && Gate::allows('manage-billing', $team);
    }

    /** The team's most recent subscription, live or ended. */
    #[Computed]
    public function subscription(): ?BillingSubscription
    {
        return $this->resolveCurrentTeam()?->subscription()->with(['plan', 'pendingPlan'])->first();
    }

    /** @return Collection<int, BillingPlan> */
    #[Computed]
    public function plans(): Collection
    {
        return BillingPlan::where('is_active', true)->orderBy('price_monthly_cents')->orderBy('name')->get();
    }

    #[Computed]
    public function nextInvoice(): ?BillingInvoice
    {
        // team scoping comes from BillingInvoice's HasTeamScope global scope
        return BillingInvoice::where('status', 'open')->orderBy('due_date')->first();
    }

    #[Computed]
    public function overdueInvoice(): ?BillingInvoice
    {
        return BillingInvoice::overdue()->orderBy('due_date')->first();
    }

    public function subscribe(int $planId, string $cycle = 'monthly'): void
    {
        $team = $this->managedTeam();
        $plan = BillingPlan::find($planId);

        $this->attempt(
            fn (SubscriptionService $service) => $service->subscribe($team, $plan ?? throw BillingRuleViolation::because('plan_missing', 'That plan does not exist.'), $cycle, Auth::user()),
            'Subscribed.'
        );
    }

    public function changePlan(int $planId, string $cycle = 'monthly'): void
    {
        $this->managedTeam();
        $plan = BillingPlan::find($planId);

        $this->attempt(
            fn (SubscriptionService $service) => $service->changePlan($this->liveOrFail(), $plan ?? throw BillingRuleViolation::because('plan_missing', 'That plan does not exist.'), $cycle, Auth::user()),
            'Plan change saved.'
        );
    }

    public function keepCurrentPlan(): void
    {
        $this->managedTeam();

        $this->attempt(function (SubscriptionService $service) {
            $live = $this->liveOrFail();

            return $service->changePlan($live, $live->plan, $live->billing_cycle, Auth::user());
        }, 'Keeping the current plan.');
    }

    public function cancelAtPeriodEnd(): void
    {
        $this->managedTeam();

        $this->attempt(fn (SubscriptionService $service) => $service->cancelAtPeriodEnd($this->liveOrFail(), Auth::user()), 'Cancellation scheduled.');
    }

    public function resume(): void
    {
        $this->managedTeam();

        $this->attempt(fn (SubscriptionService $service) => $service->resume($this->liveOrFail(), Auth::user()), 'Subscription resumed.');
    }

    private function liveOrFail(): BillingSubscription
    {
        $live = $this->resolveCurrentTeam()?->liveSubscription()->with('plan')->first();

        return $live ?? throw BillingRuleViolation::because('invalid_state', 'This team has no running subscription.');
    }

    private function attempt(callable $action, string $notice): void
    {
        $this->resetErrorBag('billing');

        try {
            app()->call($action);
            $this->notice = $notice;
        } catch (BillingRuleViolation $e) {
            $this->notice = null;
            $this->addError('billing', $e->getMessage());
        }

        unset($this->subscription, $this->nextInvoice, $this->overdueInvoice);
    }

    public function render()
    {
        return view('livewire.billing.billing-overview');
    }
}
