<?php

namespace Tests\Feature\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSubscription;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class AdvanceSubscriptionsTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-10 06:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function advance(): void
    {
        $this->artisan('billing:advance-subscriptions')->assertSuccessful();
    }

    private function invoices(BillingSubscription $sub)
    {
        return BillingInvoice::withoutGlobalScope('team')->where('subscription_id', $sub->id)->orderBy('period_start')->get();
    }

    public function test_an_ended_period_rolls_forward_and_the_renewal_is_invoiced(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan(['price_monthly_cents' => 5000]), [
            'current_period_start' => '2026-11-09 06:00:00',
            'current_period_end' => '2026-12-09 06:00:00',
        ]);

        $this->advance();

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertTrue($sub->current_period_start->equalTo('2026-12-09 06:00:00'));
        $this->assertTrue($sub->current_period_end->equalTo('2027-01-09 06:00:00'));
        $invoice = $this->invoices($sub)->sole();
        $this->assertTrue($invoice->period_start->equalTo('2026-12-09 06:00:00'));
        $this->assertSame(5000, $invoice->total_cents);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan(), [
            'current_period_start' => '2026-11-09 06:00:00',
            'current_period_end' => '2026-12-09 06:00:00',
        ]);

        $this->advance();
        $snapshot = [$sub->fresh()->current_period_end->toIso8601String(), $this->invoices($sub)->count(), BillingInvoice::withoutGlobalScope('team')->max('invoice_number')];
        $this->advance();

        $this->assertSame($snapshot, [$sub->fresh()->current_period_end->toIso8601String(), $this->invoices($sub)->count(), BillingInvoice::withoutGlobalScope('team')->max('invoice_number')]);
    }

    public function test_a_period_still_running_is_left_alone(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan(), [
            'current_period_start' => '2026-12-01 06:00:00',
            'current_period_end' => '2027-01-01 06:00:00',
        ]);

        $this->advance();

        $this->assertTrue($sub->fresh()->current_period_end->equalTo('2027-01-01 06:00:00'));
        $this->assertCount(0, $this->invoices($sub), 'advance does not invoice a period that was never invoiced and has not just been rolled');
    }

    public function test_every_missed_period_is_caught_up_and_invoiced_once(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan(), [
            'current_period_start' => '2026-09-09 06:00:00',
            'current_period_end' => '2026-10-09 06:00:00',
        ]);

        $this->advance();

        $this->assertTrue($sub->fresh()->current_period_end->equalTo('2027-01-09 06:00:00'));
        $this->assertSame(
            ['2026-10-09', '2026-11-09', '2026-12-09'],
            $this->invoices($sub)->map(fn ($i) => $i->period_start->toDateString())->all()
        );
        $this->assertCount(3, array_unique($this->invoices($sub)->pluck('invoice_number')->all()));
    }

    public function test_an_annual_subscription_rolls_by_a_year(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan(['price_annual_cents' => 120000]), [
            'billing_cycle' => 'annual',
            'current_period_start' => '2025-12-09 06:00:00',
            'current_period_end' => '2026-12-09 06:00:00',
        ]);

        $this->advance();

        $this->assertTrue($sub->fresh()->current_period_end->equalTo('2027-12-09 06:00:00'));
        $this->assertSame(120000, $this->invoices($sub)->sole()->total_cents);
    }

    public function test_a_scheduled_cancellation_ends_the_subscription_at_the_period_end_without_a_new_invoice(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan(), [
            'cancel_at_period_end' => true,
            'current_period_start' => '2026-11-09 06:00:00',
            'current_period_end' => '2026-12-09 06:00:00',
        ]);

        $this->advance();

        $sub->refresh();
        $this->assertSame('canceled', $sub->status);
        $this->assertTrue($sub->ended_at->equalTo('2026-12-09 06:00:00'));
        $this->assertNotNull($sub->canceled_at);
        $this->assertCount(0, $this->invoices($sub));
    }

    public function test_a_pending_plan_change_applies_at_renewal_and_the_new_price_is_billed(): void
    {
        $owner = $this->makeOwner();
        $small = $this->makePlan(['price_monthly_cents' => 1000]);
        $big = $this->makePlan(['price_monthly_cents' => 9000, 'price_annual_cents' => 90000]);
        $sub = $this->makeSubscription($owner->currentTeam, $small, [
            'pending_plan_id' => $big->id,
            'pending_billing_cycle' => 'annual',
            'current_period_start' => '2026-11-09 06:00:00',
            'current_period_end' => '2026-12-09 06:00:00',
        ]);

        $this->advance();

        $sub->refresh();
        $this->assertSame($big->id, $sub->plan_id);
        $this->assertSame('annual', $sub->billing_cycle);
        $this->assertNull($sub->pending_plan_id);
        $this->assertTrue($sub->current_period_end->equalTo('2027-12-09 06:00:00'));
        $this->assertSame(90000, $this->invoices($sub)->sole()->total_cents);
    }

    public function test_an_expired_trial_becomes_active_and_gets_its_first_invoice_but_no_charge(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan(['price_monthly_cents' => 7000]), [
            'status' => 'trialing',
            'trial_ends_at' => '2026-12-08 06:00:00',
            'current_period_start' => '2026-11-24 06:00:00',
            'current_period_end' => '2026-12-08 06:00:00',
        ]);

        $this->advance();

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertTrue($sub->current_period_start->equalTo('2026-12-08 06:00:00'));
        $this->assertTrue($sub->current_period_end->equalTo('2027-01-08 06:00:00'));
        $invoice = $this->invoices($sub)->sole();
        $this->assertSame('open', $invoice->status);
        $this->assertSame(7000, $invoice->total_cents);
        $this->assertSame(0, BillingPayment::withoutGlobalScope('team')->count(), 'nothing is ever charged automatically');
    }

    public function test_a_trial_that_has_not_ended_is_untouched(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan(), [
            'status' => 'trialing',
            'trial_ends_at' => '2026-12-20 06:00:00',
            'current_period_end' => '2026-12-20 06:00:00',
        ]);

        $this->advance();

        $this->assertSame('trialing', $sub->fresh()->status);
        $this->assertCount(0, $this->invoices($sub));
    }

    public function test_a_trial_cancelled_before_it_ends_ends_without_ever_billing(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan(), [
            'status' => 'trialing',
            'cancel_at_period_end' => true,
            'trial_ends_at' => '2026-12-08 06:00:00',
            'current_period_end' => '2026-12-08 06:00:00',
        ]);

        $this->advance();

        $this->assertSame('canceled', $sub->fresh()->status);
        $this->assertCount(0, $this->invoices($sub));
    }

    public function test_an_overdue_open_invoice_moves_the_subscription_to_past_due(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $sub = $this->makeSubscription($team, $this->makePlan());
        $this->makeInvoice($team, ['subscription_id' => $sub->id, 'due_date' => now()->subDays(2)]);

        $this->advance();

        $this->assertSame('past_due', $sub->fresh()->status);
    }

    public function test_an_invoice_not_yet_due_or_inside_a_grace_period_does_not_make_it_past_due(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $sub = $this->makeSubscription($team, $this->makePlan());
        $this->makeInvoice($team, ['subscription_id' => $sub->id, 'due_date' => now()->addDay()]);
        $this->makeInvoice($team, ['subscription_id' => $sub->id, 'due_date' => now()->subDays(5), 'grace_until' => now()->addDays(2)]);

        $this->advance();

        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_past_due_returns_to_active_once_nothing_is_overdue(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $sub = $this->makeSubscription($team, $this->makePlan(), ['status' => 'past_due']);
        $this->makeInvoice($team, ['subscription_id' => $sub->id, 'status' => 'paid', 'due_date' => now()->subDays(2), 'amount_paid_cents' => 50000, 'paid_at' => now()]);

        $this->advance();

        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_it_never_cancels_a_past_due_subscription_on_its_own(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $sub = $this->makeSubscription($team, $this->makePlan());
        $this->makeInvoice($team, ['subscription_id' => $sub->id, 'due_date' => now()->subDays(400)]);

        $this->advance();

        $this->assertSame('past_due', $sub->fresh()->status);
        $this->assertNull($sub->fresh()->canceled_at);
    }

    public function test_it_is_scheduled_so_it_runs_before_invoice_generation(): void
    {
        $events = collect(app(Schedule::class)->events());
        $find = fn (string $name) => $events->first(fn ($e) => str_contains($e->command, $name));
        [$h1, $m1] = array_map('intval', array_slice(explode(' ', $find('billing:advance-subscriptions')->expression), 0, 2));
        [$h2, $m2] = array_map('intval', array_slice(explode(' ', $find('billing:generate-invoices')->expression), 0, 2));

        $this->assertLessThan($m2 + $h2 * 60, $m1 + $h1 * 60);
    }
}
