<?php

namespace Tests\Feature\Analytics;

use App\Models\BillingInvoice;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class MetricsCollectorTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function collect(Team $team): array
    {
        return app(MetricsCollector::class)->collect($team);
    }

    public function test_it_counts_open_and_overdue_invoices_and_sums_the_overdue_balance(): void
    {
        $team = $this->makeCompany();
        $this->makeInvoice($team, ['due_date' => now()->addDays(5)]);                                      // open, not due
        $this->makeInvoice($team, ['due_date' => now()->subDays(2), 'total_cents' => 100000]);            // overdue
        $this->makeInvoice($team, ['due_date' => now()->subDays(9), 'total_cents' => 50000, 'amount_paid_cents' => 20000]); // overdue, part paid: owes 30000
        $this->makeInvoice($team, ['status' => 'paid', 'due_date' => now()->subDays(9), 'paid_at' => now()->subDays(8)]);
        $this->makeInvoice($team, ['status' => 'draft', 'due_date' => now()->subDays(9)]);
        $this->makeInvoice($team, ['status' => 'void', 'due_date' => now()->subDays(9)]);

        $result = $this->collect($team);

        $this->assertSame(3, $result['metrics']['open_invoices']);
        $this->assertSame(2, $result['metrics']['overdue_invoices']);
        $this->assertSame(130000, $result['metrics']['overdue_cents'], 'the balance still owed, not the invoice totals');
        $this->assertSame('ZAR', $result['metrics']['currency']);
    }

    public function test_overdue_boundaries_match_the_apps_own_overdue_rule(): void
    {
        $team = $this->makeCompany();
        $this->makeInvoice($team, ['due_date' => now()]);                                                  // due this very second: not yet overdue
        $this->makeInvoice($team, ['due_date' => now()->subSecond()]);                                     // overdue
        $this->makeInvoice($team, ['due_date' => now()->subDays(4), 'grace_until' => now()->addMinute()]); // a person granted grace: not overdue
        $this->makeInvoice($team, ['due_date' => now()->subDays(4), 'grace_until' => now()]);              // grace ended this second: overdue

        $result = $this->collect($team);

        $this->assertSame(4, $result['metrics']['open_invoices']);
        $this->assertSame(2, $result['metrics']['overdue_invoices']);
    }

    public function test_mrr_normalises_every_live_subscription_to_a_month_in_whole_cents(): void
    {
        $team = $this->makeCompany();
        $this->makeSubscription($team, $this->makePlan(['price_monthly_cents' => 29900]), ['status' => 'active']);
        // The team can hold one live subscription only, so older ones are ended or canceled; build the rest through other teams below.
        $result = $this->collect($team);
        $this->assertSame(29900, $result['metrics']['mrr_cents']);

        $annual = $this->makeCompany();
        $this->makeSubscription($annual, $this->makePlan(['price_annual_cents' => 1000001]), ['billing_cycle' => 'annual']);
        $this->assertSame(83333, $this->collect($annual)['metrics']['mrr_cents'], '1000001 / 12 = 83333.4 rounded to the cent');

        $rounding = $this->makeCompany();
        $this->makeSubscription($rounding, $this->makePlan(['price_annual_cents' => 1000006]), ['billing_cycle' => 'annual']);
        $this->assertSame(83334, $this->collect($rounding)['metrics']['mrr_cents'], '1000006 / 12 = 83333.83 rounds up');
    }

    public function test_only_subscriptions_that_are_billed_count_towards_mrr(): void
    {
        $plan = $this->makePlan(['price_monthly_cents' => 10000]);
        $expected = ['active' => 10000, 'past_due' => 10000, 'trialing' => 0, 'canceled' => 0, 'ended' => 0];

        foreach ($expected as $status => $mrr) {
            $team = $this->makeCompany();
            $this->makeSubscription($team, $plan, ['status' => $status]);

            $this->assertSame($mrr, $this->collect($team)['metrics']['mrr_cents'], "status {$status}");
        }
    }

    public function test_a_free_plan_adds_nothing_and_a_company_with_no_subscription_reports_zero_in_its_invoice_currency(): void
    {
        $free = $this->makeCompany();
        $this->makeSubscription($free, $this->makePlan(['price_monthly_cents' => 0, 'price_annual_cents' => 0]));
        $this->assertSame(0, $this->collect($free)['metrics']['mrr_cents']);

        $churned = $this->makeCompany();
        $this->makeInvoice($churned, ['status' => 'paid', 'currency' => 'USD', 'paid_at' => now()->subDays(30)]);
        $result = $this->collect($churned);
        $this->assertSame(0, $result['metrics']['mrr_cents']);
        $this->assertSame('USD', $result['metrics']['currency']);
    }

    public function test_a_company_with_no_money_at_all_omits_money_keys_rather_than_inventing_a_currency(): void
    {
        $result = $this->collect($this->makeCompany());

        $this->assertSame(['open_invoices' => 0, 'overdue_invoices' => 0], $result['metrics']);
        $this->assertArrayHasKey('mrr_cents', $result['omitted']);
        $this->assertArrayHasKey('overdue_cents', $result['omitted']);
    }

    public function test_a_company_in_more_than_one_currency_omits_money_keys_and_logs_a_warning(): void
    {
        Log::spy();
        $team = $this->makeCompany();
        $this->makeSubscription($team, $this->makePlan(['currency' => 'ZAR', 'price_monthly_cents' => 10000]));
        $this->makeInvoice($team, ['currency' => 'USD', 'due_date' => now()->subDays(3), 'total_cents' => 5000]);

        $result = $this->collect($team);

        $this->assertSame(['open_invoices' => 1, 'overdue_invoices' => 1], $result['metrics'], 'counts survive, money and currency are left out');
        $this->assertArrayHasKey('mrr_cents', $result['omitted']);
        $this->assertArrayHasKey('overdue_cents', $result['omitted']);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_old_invoice_in_another_currency_does_not_block_money_when_the_live_figures_agree(): void
    {
        $team = $this->makeCompany();
        $this->makeSubscription($team, $this->makePlan(['currency' => 'EUR', 'price_monthly_cents' => 7000]));
        $this->makeInvoice($team, ['status' => 'paid', 'currency' => 'USD', 'paid_at' => now()->subDays(90)]);

        $result = $this->collect($team);

        $this->assertSame(7000, $result['metrics']['mrr_cents']);
        $this->assertSame('EUR', $result['metrics']['currency']);
    }

    public function test_company_a_numbers_never_include_company_b_rows(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $this->makeInvoice($a, ['due_date' => now()->subDays(2), 'total_cents' => 1000]);
        $this->makeSubscription($a, $this->makePlan(['price_monthly_cents' => 2000]));
        foreach (range(1, 4) as $i) {
            $this->makeInvoice($b, ['due_date' => now()->subDays($i), 'total_cents' => 900000]);
        }
        $this->makeSubscription($b, $this->makePlan(['price_monthly_cents' => 777000]));

        $resultA = $this->collect($a);
        $resultB = $this->collect($b);

        $this->assertSame(['mrr_cents' => 2000, 'open_invoices' => 1, 'overdue_invoices' => 1, 'overdue_cents' => 1000, 'currency' => 'ZAR'], $resultA['metrics']);
        $this->assertSame(['mrr_cents' => 777000, 'open_invoices' => 4, 'overdue_invoices' => 4, 'overdue_cents' => 3600000, 'currency' => 'ZAR'], $resultB['metrics']);
    }

    public function test_the_numbers_do_not_depend_on_who_is_signed_in(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $this->makeInvoice($a, ['due_date' => now()->subDays(2)]);
        $this->makeInvoice($b, ['due_date' => now()->subDays(2)]);
        $this->makeInvoice($b, ['due_date' => now()->subDays(3)]);
        $this->makeSubscription($b, $this->makePlan(['price_monthly_cents' => 4200]));
        $this->makeSubscription($a, $this->makePlan(['price_monthly_cents' => 900]));

        $anonymous = $this->collect($b);

        Auth::login($a->owner);   // signed in to the OTHER company
        $asOther = $this->collect($b);
        Auth::logout();

        $teamless = User::factory()->create(['current_team_id' => null]);
        Auth::login($teamless);   // the team scope fails closed for this user
        $asTeamless = $this->collect($b);

        $this->assertSame(2, $anonymous['metrics']['overdue_invoices']);
        $this->assertSame(4200, $anonymous['metrics']['mrr_cents']);
        $this->assertSame($anonymous, $asOther);
        $this->assertSame($anonymous, $asTeamless);
    }

    public function test_only_company_teams_are_published_for(): void
    {
        $company = $this->makeCompany();
        $unlinked = $this->makeCompany();
        $unlinked->forceFill(['infodot_team_uuid' => null])->save();
        $personalLinked = $this->makeOwner()->currentTeam;
        $this->linkToInfoDot($personalLinked);
        $blank = $this->makeCompany();
        $blank->forceFill(['infodot_team_uuid' => ''])->save();

        $ids = app(MetricsCollector::class)->companies()->pluck('id')->all();

        $this->assertSame([$company->id], $ids);
        $this->assertSame([$company->id], app(MetricsCollector::class)->companies($company->infodot_team_uuid)->pluck('id')->all());
        $this->assertSame([], app(MetricsCollector::class)->companies($personalLinked->infodot_team_uuid)->pluck('id')->all(), 'a personal team is never a company, even when linked');
    }

    public function test_it_only_ever_returns_keys_defined_in_the_analytics_contract(): void
    {
        $team = $this->makeCompany();
        $this->makeInvoice($team, ['due_date' => now()->subDays(2)]);
        $this->makeSubscription($team, $this->makePlan());

        $keys = array_keys($this->collect($team)['metrics']);

        // Dot.Analytics config/metrics.php, platforms.billing.metrics (+ the special `currency` key).
        $contract = ['mrr_cents', 'open_invoices', 'overdue_invoices', 'overdue_cents', 'currency'];
        $this->assertSame([], array_values(array_diff($keys, $contract)));
        $this->assertSame([], array_values(array_diff($contract, $keys)));
    }

    public function test_every_value_is_a_whole_non_negative_integer(): void
    {
        $team = $this->makeCompany();
        $this->makeInvoice($team, ['due_date' => now()->subDays(2), 'total_cents' => 100, 'amount_paid_cents' => 250]); // overpaid: negative balance
        $this->makeSubscription($team, $this->makePlan(['price_monthly_cents' => 123]));

        $metrics = $this->collect($team)['metrics'];

        foreach (['mrr_cents', 'open_invoices', 'overdue_invoices', 'overdue_cents'] as $key) {
            $this->assertIsInt($metrics[$key], $key);
            $this->assertGreaterThanOrEqual(0, $metrics[$key], $key);
        }
    }

    public function test_the_collector_does_not_query_once_per_invoice(): void
    {
        $team = $this->makeCompany();
        foreach (range(1, 30) as $i) {
            $this->makeInvoice($team, ['due_date' => now()->subDays($i)]);
        }

        \DB::enableQueryLog();
        $this->collect($team);
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $queries);
        $this->assertSame(30, BillingInvoice::withoutGlobalScope('team')->where('team_id', $team->id)->count());
    }
}
