<?php

namespace App\Providers;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        // Who may change a team's billing: subscribe, change plan, cancel, resume, record a payment,
        // finalize or void an invoice. The team owner and Jetstream admins only. A platform operator
        // reviews dunning cases through their own policy and is deliberately not a team manager.
        Gate::define('manage-billing', fn (User $user, Team $team): bool => $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin'));

        // Who may start an AI spend analysis (it costs money per call): owner, admins and editors;
        // never a read-only viewer.
        Gate::define('run-ai-analysis', fn (User $user, Team $team): bool => $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin') || $user->hasTeamRole($team, 'editor'));

        // Who may edit the shared plan catalogue: platform operators. A team's admins manage their own
        // subscription, never the prices everyone else is offered.
        Gate::define('manage-plans', fn (User $user): bool => (bool) $user->is_platform_operator);

        // Who may read a team's invoices, subscription and usage: any member, whatever their role.
        Gate::define('view-billing', fn (User $user, Team $team): bool => $user->belongsToTeam($team));
    }
}
