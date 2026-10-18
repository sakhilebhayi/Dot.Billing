<?php

namespace App\Services\Analytics;

use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The daily numbers this app publishes to Dot.Analytics, for one company at a time. Pure queries: nothing
 * is written, and only counts and whole-cent sums come out (no names, emails, invoice numbers or rows).
 *
 * The keys are exactly Dot.Analytics' catalogue for platform `billing` (config/metrics.php there):
 * mrr_cents, open_invoices, overdue_invoices, overdue_cents, and `currency` for the two money keys.
 *
 * Scoping: a company is a non-personal team linked to an InfoDot company uuid. Every query here names the
 * team and bypasses the signed-in-user team scope explicitly, so the numbers are the same whoever (or
 * nobody) is authenticated, and one company's rows can never leak into another's numbers.
 *
 * Money: one snapshot carries ONE currency. When a company's live money is in more than one currency the
 * money keys are left out (and a warning is logged) rather than adding currencies together or guessing.
 */
class MetricsCollector
{
    /**
     * Subscriptions that are billing the company now. A trial is live (it entitles) but is not revenue yet,
     * so it is not MRR; a past_due subscription is still the company's contracted, invoiced revenue.
     */
    public const MRR_STATUSES = ['active', 'past_due'];

    /** @return Collection<int, Team> companies Analytics can be told about, optionally just one uuid */
    public function companies(?string $uuid = null): Collection
    {
        return Team::query()
            ->where('personal_team', false)
            ->whereNotNull('infodot_team_uuid')
            ->where('infodot_team_uuid', '!=', '')
            ->when($uuid !== null, fn ($q) => $q->where('infodot_team_uuid', $uuid))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{metrics: array<string, int|string>, omitted: array<string, string>}
     */
    public function collect(Team $team, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= now();

        $invoices = fn () => BillingInvoice::withoutGlobalScope('team')->where('team_id', $team->id);

        $open = $invoices()->where('status', 'open')->count();

        $overdueRow = $invoices()->overdue($asOf)
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN total_cents - amount_paid_cents > 0 THEN total_cents - amount_paid_cents ELSE 0 END), 0) as owed_cents')
            ->first();

        $metrics = [
            'open_invoices' => $open,
            'overdue_invoices' => (int) $overdueRow->invoice_count,
        ];
        $omitted = [];

        $currency = $this->singleCurrency($team, $overdueRow, $omitted);

        if ($currency !== null) {
            $metrics = [
                'mrr_cents' => $this->mrrCents($team),
                'open_invoices' => $metrics['open_invoices'],
                'overdue_invoices' => $metrics['overdue_invoices'],
                'overdue_cents' => (int) $overdueRow->owed_cents,
                'currency' => $currency,
            ];
        }

        return ['metrics' => $metrics, 'omitted' => $omitted];
    }

    /**
     * The one currency this company's money is in, or null (with the reason in $omitted) when there is none
     * or more than one. Looks first at what drives the two money numbers today (subscriptions that count
     * towards MRR, overdue invoices); a company with neither falls back to its billing history so that a
     * churned company still reports a true zero.
     *
     * @param  array<string, string>  $omitted
     */
    private function singleCurrency(Team $team, object $overdueRow, array &$omitted): ?string
    {
        $live = $this->liveSubscriptions($team)->join('billing_plans', 'billing_plans.id', '=', 'billing_subscriptions.plan_id')
            ->distinct()->pluck('billing_plans.currency');

        if ((int) $overdueRow->invoice_count > 0) {
            $live = $live->merge(BillingInvoice::withoutGlobalScope('team')->where('team_id', $team->id)->overdue()->distinct()->pluck('currency'));
        }

        $currencies = $live->map(fn ($c) => strtoupper((string) $c))->unique()->values();

        if ($currencies->isEmpty()) {
            $currencies = BillingInvoice::withoutGlobalScope('team')->where('team_id', $team->id)->distinct()->pluck('currency')
                ->merge(
                    BillingSubscription::withoutGlobalScope('team')->where('billing_subscriptions.team_id', $team->id)
                        ->join('billing_plans', 'billing_plans.id', '=', 'billing_subscriptions.plan_id')->distinct()->pluck('billing_plans.currency')
                )
                ->map(fn ($c) => strtoupper((string) $c))->unique()->values();
        }

        if ($currencies->count() === 1) {
            return $currencies->first();
        }

        $reason = $currencies->isEmpty()
            ? 'no subscriptions or invoices, so there is no currency to report money in'
            : 'the company has money in more than one currency ('.$currencies->implode(', ').') and a snapshot carries one';

        $omitted = ['mrr_cents' => $reason, 'overdue_cents' => $reason];

        if ($currencies->isNotEmpty()) {
            Log::warning('Analytics snapshot: money keys omitted for a multi-currency company.', [
                'team_id' => $team->id, 'currencies' => $currencies->all(),
            ]);
        }

        return null;
    }

    /** Whole cents per month: monthly plans as priced, annual plans summed then divided by 12 once, half up. */
    private function mrrCents(Team $team): int
    {
        $rows = $this->liveSubscriptions($team)
            ->join('billing_plans', 'billing_plans.id', '=', 'billing_subscriptions.plan_id')
            ->groupBy('billing_subscriptions.billing_cycle')
            ->select('billing_subscriptions.billing_cycle', DB::raw('COALESCE(SUM(billing_plans.price_monthly_cents), 0) as monthly_sum'), DB::raw('COALESCE(SUM(billing_plans.price_annual_cents), 0) as annual_sum'))
            ->get()
            ->keyBy('billing_cycle');

        $monthly = (int) ($rows['monthly']->monthly_sum ?? 0);
        $annual = (int) ($rows['annual']->annual_sum ?? 0);

        return $monthly + intdiv($annual + 6, 12);
    }

    /** @return Builder<BillingSubscription> */
    private function liveSubscriptions(Team $team): Builder
    {
        return BillingSubscription::withoutGlobalScope('team')
            ->where('billing_subscriptions.team_id', $team->id)
            ->whereIn('billing_subscriptions.status', self::MRR_STATUSES);
    }
}
