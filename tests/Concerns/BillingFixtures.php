<?php

namespace Tests\Concerns;

use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Small, explicit builders for billing tests. Nothing random leaks into
 * assertions: slugs and invoice numbers are unique per call but never asserted on.
 */
trait BillingFixtures
{
    protected function makeOwner(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    protected function makeMember(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    protected function makeOperator(): User
    {
        return User::factory()->create(['is_platform_operator' => true]);
    }

    protected function linkToInfoDot(Team $team, ?string $uuid = null): string
    {
        $uuid ??= (string) Str::uuid();
        $team->forceFill(['infodot_team_uuid' => $uuid])->save();

        return $uuid;
    }

    protected function makePlan(array $attributes = []): BillingPlan
    {
        $slug = $attributes['slug'] ?? 'plan-'.Str::lower(Str::random(10));

        return BillingPlan::create(array_merge([
            'name' => 'Plan '.$slug,
            'slug' => $slug,
            'price_monthly_cents' => 29900,
            'price_annual_cents' => 299000,
            'currency' => 'ZAR',
            'seat_limit' => 10,
            'storage_gb' => 50,
            'features' => ['api_access'],
            'is_active' => true,
        ], $attributes));
    }

    protected function makeSubscription(Team $team, BillingPlan $plan, array $attributes = []): BillingSubscription
    {
        return BillingSubscription::create(array_merge([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ], $attributes));
    }

    protected function makeInvoice(Team $team, array $attributes = []): BillingInvoice
    {
        $total = $attributes['total_cents'] ?? 50000;
        unset($attributes['total_cents']);

        return BillingInvoice::create(array_merge([
            'team_id' => $team->id,
            'invoice_number' => 'T-'.Str::upper(Str::random(12)),
            'status' => 'open',
            'subtotal_cents' => $total,
            'tax_cents' => 0,
            'total_cents' => $total,
            'currency' => 'ZAR',
            'issued_at' => now()->subDays(1),
            'due_date' => now()->addDays(13),
        ], $attributes));
    }
}
