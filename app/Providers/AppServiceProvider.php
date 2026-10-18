<?php

namespace App\Providers;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Who may change a team's billing: subscribe, change plan, cancel, resume, record a payment,
        // finalize or void an invoice. The team owner and Jetstream admins only. A platform operator
        // reviews dunning cases through their own policy and is deliberately not a team manager.
        Gate::define('manage-billing', fn (User $user, Team $team): bool => $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin'));

        // Who may edit the shared plan catalogue: platform operators. A team's admins manage their own
        // subscription, never the prices everyone else is offered.
        Gate::define('manage-plans', fn (User $user): bool => (bool) $user->is_platform_operator);

        // Who may read a team's invoices, subscription and usage: any member, whatever their role.
        Gate::define('view-billing', fn (User $user, Team $team): bool => $user->belongsToTeam($team));
    }
}
