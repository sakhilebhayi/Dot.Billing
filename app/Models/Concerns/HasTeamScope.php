<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dot.Billing is team-scoped, not single-user (Jetstream Teams — see
 * User::currentTeam / HasTeams). Every model that owns a team_id column
 * applies this trait so a query against it is scoped to the authenticated
 * user's current team by default, the same way Dot.Mines' HasTeamFilters
 * scopes every tenant-owned model to the current team — the goal is that
 * a forgotten where('team_id', ...) call in a future controller or
 * Livewire component can no longer leak another team's rows, because the
 * model itself never returns unscoped results while a user is
 * authenticated with a current team.
 *
 * Deliberately NOT applied to BillingPlan, which is a shared/global
 * pricing catalog with no team_id column at all, and not applied to
 * BillingInvoiceItem, which has no team_id column of its own — it is
 * only ever reached through an already-scoped BillingInvoice's items()
 * relation, so scoping the parent is sufficient.
 *
 * mass-assignment still sets team_id explicitly at create time; this
 * scope only governs reads.
 */
trait HasTeamScope
{
    protected static function bootHasTeamScope(): void
    {
        static::addGlobalScope('team', function (Builder $builder): void {
            $team = Auth::check() ? Auth::user()->currentTeam : null;

            if ($team) {
                $builder->where($builder->getModel()->getTable().'.team_id', $team->id);
            } elseif (Auth::check()) {
                // Authenticated but no current team: fail closed rather than
                // returning every team's rows.
                $builder->whereRaw('1 = 0');
            }
        });
    }
}
