<?php

namespace App\Livewire\Billing;

use App\Models\BillingSubscription;
use App\Models\DunningCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DunningQueue extends Component
{
    #[Computed]
    public function cases(): Collection
    {
        return DunningCase::where('status', 'open')
            ->with(['team', 'invoice', 'payment'])
            ->orderBy('created_at')
            ->get();
    }

    public function extend(int $id, int $days): void
    {
        $case = DunningCase::findOrFail($id);
        Gate::authorize('review', $case);

        $case->invoice?->update(['due_date' => now()->addDays($days)]);
        $case->update(['status' => 'extended', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);

        unset($this->cases);
    }

    public function cancelSubscription(int $id): void
    {
        $case = DunningCase::findOrFail($id);
        Gate::authorize('review', $case);

        // BillingSubscription carries HasTeamScope, which fails closed for
        // a platform_operator (authenticated, deliberately teamless) --
        // see the same reasoning on DunningCase::invoice()/payment().
        BillingSubscription::withoutGlobalScope('team')
            ->where('team_id', $case->team_id)
            ->first()
            ?->update(['status' => 'canceled', 'canceled_at' => now()]);

        $case->update(['status' => 'canceled', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);

        unset($this->cases);
    }

    public function dismiss(int $id): void
    {
        $case = DunningCase::findOrFail($id);
        Gate::authorize('review', $case);

        $case->update(['status' => 'dismissed', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);

        unset($this->cases);
    }

    public function render(): View
    {
        return view('livewire.billing.dunning-queue');
    }
}
