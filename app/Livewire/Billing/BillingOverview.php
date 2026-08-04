<?php

namespace App\Livewire\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class BillingOverview extends Component
{
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

    /**
     * Centralises the currentTeam-can-be-null check so every caller in this
     * component is consistent.
     */
    private function resolveCurrentTeam(): ?Team
    {
        return Auth::user()?->currentTeam;
    }

    #[Computed]
    public function subscription(): ?BillingSubscription
    {
        $team = $this->resolveCurrentTeam();

        if (! $team) {
            return null;
        }

        return $team->subscription()->with('plan')->first();
    }

    #[Computed]
    public function nextInvoice(): ?BillingInvoice
    {
        // team scoping now comes from BillingInvoice's HasTeamScope global scope
        return BillingInvoice::where('status', 'open')
            ->orderBy('due_date')
            ->first();
    }

    public function render()
    {
        return view('livewire.billing.billing-overview');
    }
}
