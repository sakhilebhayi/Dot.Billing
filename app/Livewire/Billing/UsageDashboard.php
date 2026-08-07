<?php

namespace App\Livewire\Billing;

use App\Models\BillingUsageRecord;
use App\Models\Team;
use App\Services\AiBillingService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class UsageDashboard extends Component
{
    public string $selectedPeriod = 'this_month';

    public array $aiInsights = [];

    /**
     * A user removed from their last team (or never assigned one) has a
     * genuinely null `currentTeam` here — nothing in this app's routing
     * forces a team to exist before this component renders (there is no
     * EnsureTeamContext-style middleware). Send them to team creation
     * instead of letting analyzeSpend() below TypeError on a null $team.
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
    public function usageByPlatform(): array
    {
        // team scoping now comes from BillingUsageRecord's HasTeamScope global scope
        return BillingUsageRecord::query()
            ->selectRaw('platform, metric, SUM(quantity) as total')
            ->groupBy('platform', 'metric')
            ->get()
            ->groupBy('platform')
            ->toArray();
    }

    public function analyzeSpend(): void
    {
        $team = $this->resolveCurrentTeam();

        if (! $team) {
            abort(403, 'No active team selected.');
        }

        $service = new AiBillingService;
        $result = $service->analyzeSpend($team, $this->usageByPlatform);
        $this->aiInsights = $result['insights'];
    }

    public function render()
    {
        return view('livewire.billing.usage-dashboard');
    }
}
