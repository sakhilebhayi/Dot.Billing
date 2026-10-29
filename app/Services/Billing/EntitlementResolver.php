<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\Team;

/**
 * What a company is entitled to, as the other Dot apps should read it. Always built from the team's
 * own subscription rows (never from the signed-in user), so it is the same answer for every caller.
 *
 *  status   active | trialing | past_due   the team has a live subscription; plan limits apply
 *           canceled                        its last subscription ended; free-tier limits apply
 *           none                            it never subscribed; free-tier limits apply
 *  limits   null means unlimited
 *  seat_limit  plan seats plus the extra seats that are paid for (extra_seats); unlimited stays unlimited
 *
 * A company that is only in the registry (no team yet) is answered as 'none'. A registry company InfoDot deleted is
 * unknown here (the controller answers 404). past_due keeps the plan's limits on purpose: whether to restrict is the consuming app's call.
 */
class EntitlementResolver
{
    /**
     * @return array<string, mixed>
     */
    public function forTeam(Team $team): array
    {
        $uuid = (string) $team->infodot_team_uuid;

        $live = BillingSubscription::withoutGlobalScope('team')->with('plan')
            ->where('team_id', $team->id)->whereIn('status', BillingSubscription::LIVE_STATUSES)
            ->latest('id')->first();

        if ($live) {
            $plan = $live->plan;
            $extraSeats = max(0, (int) $live->extra_seats);

            return [
                'company_uuid' => $uuid,
                'status' => $live->status,
                'plan' => ['slug' => $plan->slug, 'name' => $plan->name],
                // Settled extra seats only: a seat bought but not yet paid for is not in extra_seats.
                'seat_limit' => $plan->seat_limit === null ? null : (int) $plan->seat_limit + $extraSeats,
                'extra_seats' => $extraSeats,
                'storage_gb' => $plan->storage_gb,
                'features' => array_values($plan->features ?? []),
                'period_end' => $live->current_period_end?->toIso8601String(),
                'trial_ends_at' => $live->status === 'trialing' ? $live->trial_ends_at?->toIso8601String() : null,
                'cancel_at_period_end' => (bool) $live->cancel_at_period_end,
            ];
        }

        $last = BillingSubscription::withoutGlobalScope('team')
            ->where('team_id', $team->id)->latest('id')->first();

        return $this->freeTier($uuid, $last ? 'canceled' : 'none', $last ? ($last->ended_at ?? $last->canceled_at)?->toIso8601String() : null);
    }

    /**
     * A company InfoDot has registered (infodot_companies, docs/company-registry.md) that has no team here yet:
     * it cannot have subscribed, so it is answered exactly like a team that never subscribed.
     *
     * @return array<string, mixed>
     */
    public function forRegisteredCompany(string $companyUuid): array
    {
        return $this->freeTier(strtolower($companyUuid), 'none', null);
    }

    /**
     * @return array<string, mixed>
     */
    private function freeTier(string $uuid, string $status, ?string $periodEnd): array
    {
        $free = (array) config('billing.free_tier', []);

        return [
            'company_uuid' => $uuid,
            'status' => $status,
            'plan' => null,
            'seat_limit' => $free['seat_limit'] ?? null,
            'extra_seats' => 0,
            'storage_gb' => $free['storage_gb'] ?? null,
            'features' => array_values($free['features'] ?? []),
            'period_end' => $periodEnd,
            'trial_ends_at' => null,
            'cancel_at_period_end' => false,
        ];
    }
}
