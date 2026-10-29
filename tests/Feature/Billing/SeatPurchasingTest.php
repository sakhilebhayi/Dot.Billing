<?php

namespace Tests\Feature\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingAuditEvent;
use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSeatChange;
use App\Models\BillingSubscription;
use App\Models\Team;
use App\Services\Billing\EntitlementResolver;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\RecordPayment;
use App\Services\Billing\SeatChange;
use App\Services\Billing\SubscriptionLifecycle;
use App\Services\Billing\SubscriptionService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PaystackFixtures;
use Tests\TestCase;

/**
 * Extra-seat purchasing (R-13). Prices in these tests are made-up fixtures; the app itself has no default.
 *
 * Frozen clock: 5 Nov 2026 09:00. The fixture subscription's period is 26 Oct 09:00 to 25 Nov 09:00, so
 * 30 days in the period and 20 days left (by calendar day).
 */
class SeatPurchasingTest extends TestCase
{
    use BillingFixtures;
    use PaystackFixtures;
    use RefreshDatabase;

    private const SEAT_PRICE = 1000;

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

    /** @return array{0: Team, 1: BillingPlan, 2: BillingSubscription} */
    private function scene(array $plan = [], array $sub = [], bool $withPrice = true): array
    {
        $team = $this->makeOwner()->currentTeam;
        $plan = $this->makePlan(array_merge(
            ['seat_limit' => 10, 'price_monthly_cents' => 29900, 'price_annual_cents' => 299000],
            $withPrice ? ['extra_seat_price_monthly_cents' => self::SEAT_PRICE, 'extra_seat_price_annual_cents' => 10000] : [],
            $plan,
        ));

        return [$team, $plan, $this->makeSubscription($team, $plan, $sub)];
    }

    private function seats(): SeatChange
    {
        return app(SeatChange::class);
    }

    private function reason(callable $fn): string
    {
        try {
            $fn();
        } catch (BillingRuleViolation $e) {
            return $e->reason;
        }

        $this->fail('Expected a BillingRuleViolation.');
    }

    private function invoices(BillingSubscription $sub)
    {
        return BillingInvoice::withoutGlobalScope('team')->where('subscription_id', $sub->id)->orderBy('id')->get();
    }

    private function pay(BillingInvoice $invoice): void
    {
        app(RecordPayment::class)->apply($invoice, $invoice->balanceMoney(), method: 'manual');
    }

    private function limit(Team $team): ?int
    {
        return app(EntitlementResolver::class)->forTeam($team)['seat_limit'];
    }

    // ---- plan price: nullable, never defaulted --------------------------------------------------------

    public function test_a_plan_has_no_extra_seat_price_unless_one_is_set(): void
    {
        $plan = $this->makePlan();

        $this->assertNull($plan->fresh()->extra_seat_price_monthly_cents);
        $this->assertNull($plan->fresh()->extra_seat_price_annual_cents);
        $this->assertNull($plan->extraSeatPriceFor('monthly'));
        $this->assertNull($plan->extraSeatPriceFor('annual'));
        $this->assertFalse($plan->sellsExtraSeats('monthly'));
    }

    public function test_extra_seat_price_for_returns_money_in_the_plan_currency_per_cycle(): void
    {
        $plan = $this->makePlan(['currency' => 'USD', 'extra_seat_price_monthly_cents' => 1250, 'extra_seat_price_annual_cents' => 12000]);

        $this->assertTrue($plan->extraSeatPriceFor('monthly')->equals(new Money(1250, 'USD')));
        $this->assertTrue($plan->extraSeatPriceFor('annual')->equals(new Money(12000, 'USD')));
        $this->assertNull($this->makePlan(['extra_seat_price_monthly_cents' => 500])->extraSeatPriceFor('annual'), 'only the cycle with a price sells');
    }

    public function test_a_new_subscription_holds_no_extra_seats(): void
    {
        [, , $sub] = $this->scene();

        $this->assertSame(0, $sub->fresh()->extra_seats);
        $this->assertNull($sub->fresh()->pending_extra_seats);
    }

    public function test_the_migration_is_safe_to_run_twice_and_to_roll_back(): void
    {
        $migration = require database_path('migrations/2026_10_29_100000_add_extra_seat_columns.php');

        $migration->up();
        $migration->up();
        $this->assertTrue(\Schema::hasColumns('billing_plans', ['extra_seat_price_monthly_cents', 'extra_seat_price_annual_cents']));

        $migration->down();
        $this->assertFalse(\Schema::hasColumn('billing_plans', 'extra_seat_price_monthly_cents'));
        $this->assertFalse(\Schema::hasColumn('billing_subscriptions', 'extra_seats'));
        $this->assertFalse(\Schema::hasTable('billing_seat_changes'));

        $migration->up();
        $this->assertTrue(\Schema::hasColumn('billing_subscriptions', 'pending_extra_seats'));
    }

    // ---- refusals --------------------------------------------------------------------------------------

    public function test_a_plan_without_an_extra_seat_price_refuses_the_purchase_and_raises_no_invoice(): void
    {
        [, , $sub] = $this->scene(withPrice: false);

        $this->assertSame('extra_seats_not_sold', $this->reason(fn () => $this->seats()->change($sub, 2, 'k1')));

        $this->assertSame(0, $this->invoices($sub)->count());
        $this->assertSame(0, BillingSeatChange::withoutGlobalScope('team')->count());
        $this->assertSame(0, $sub->fresh()->extra_seats);
    }

    public function test_a_zero_price_is_not_a_price(): void
    {
        [, , $sub] = $this->scene(['extra_seat_price_monthly_cents' => 0]);

        $this->assertSame('extra_seats_not_sold', $this->reason(fn () => $this->seats()->change($sub, 1, 'k1')));
        $this->assertSame(0, $this->invoices($sub)->count());
    }

    public function test_only_the_price_of_the_subscriptions_own_cycle_counts(): void
    {
        [, , $sub] = $this->scene(['extra_seat_price_annual_cents' => null], ['billing_cycle' => 'annual']);

        $this->assertSame('extra_seats_not_sold', $this->reason(fn () => $this->seats()->change($sub, 1, 'k1')));
    }

    public function test_a_subscription_that_is_not_live_is_refused(): void
    {
        [, , $sub] = $this->scene(sub: ['status' => 'canceled', 'ended_at' => now()->subDay()]);

        $this->assertSame('invalid_state', $this->reason(fn () => $this->seats()->change($sub, 2, 'k1')));
        $this->assertSame(0, $this->invoices($sub)->count());
    }

    public function test_a_negative_count_is_refused(): void
    {
        [, , $sub] = $this->scene();

        $this->assertSame('invalid_seat_count', $this->reason(fn () => $this->seats()->change($sub, -1, 'k1')));
    }

    public function test_a_count_above_the_configured_maximum_is_refused_and_the_maximum_itself_is_allowed(): void
    {
        config(['billing.seats.max_extra' => 5]);
        [, , $sub] = $this->scene();

        $this->assertSame('seat_limit_exceeded', $this->reason(fn () => $this->seats()->change($sub, 6, 'k1')));
        $this->assertSame(0, $this->invoices($sub)->count());

        $this->seats()->change($sub, 5, 'k2');
        $this->assertSame(1, $this->invoices($sub)->count());
    }

    public function test_the_maximum_defaults_to_500(): void
    {
        $this->assertSame(500, config('billing.seats.max_extra'));
        [, , $sub] = $this->scene();

        $this->assertSame('seat_limit_exceeded', $this->reason(fn () => $this->seats()->change($sub, 501, 'k1')));
    }

    public function test_seats_cannot_be_bought_during_a_trial(): void
    {
        [, , $sub] = $this->scene(sub: ['status' => 'trialing', 'trial_ends_at' => now()->addDays(5)]);

        $this->assertSame('trial_no_extra_seats', $this->reason(fn () => $this->seats()->change($sub, 1, 'k1')));
    }

    public function test_a_blank_or_oversized_request_key_is_refused(): void
    {
        [, , $sub] = $this->scene();

        $this->assertSame('invalid_request_key', $this->reason(fn () => $this->seats()->change($sub, 1, '  ')));
        $this->assertSame('invalid_request_key', $this->reason(fn () => $this->seats()->change($sub, 1, str_repeat('x', 101))));
    }

    public function test_buying_on_the_last_day_of_the_period_is_refused_because_nothing_is_left_to_charge(): void
    {
        [, , $sub] = $this->scene(sub: ['current_period_start' => '2026-10-06 09:00:00', 'current_period_end' => '2026-11-05 18:00:00']);

        $this->assertSame('period_ended', $this->reason(fn () => $this->seats()->change($sub, 1, 'k1')));
        $this->assertSame(0, $this->invoices($sub)->count());
    }

    // ---- adding: pro rata invoice, entitlement only after settlement -----------------------------------

    public function test_adding_seats_raises_a_pro_rata_invoice_for_the_rest_of_the_period(): void
    {
        [$team, $plan, $sub] = $this->scene();

        $change = $this->seats()->change($sub, 2, 'k1');

        $invoice = $this->invoices($sub)->sole();
        // 2 seats x 1000 = 2000; 20 of 30 days left = 1333.33 -> 1333.
        $this->assertSame(1333, $invoice->total_cents);
        $this->assertSame(1333, $invoice->subtotal_cents);
        $this->assertSame('open', $invoice->status);
        $this->assertSame('ZAR', $invoice->currency);
        $this->assertSame($invoice->id, $change->invoice_id);
        $this->assertSame(BillingSeatChange::AWAITING_PAYMENT, $change->status);
        $this->assertTrue($invoice->due_date->equalTo(now()->addDays(14)));

        $item = $invoice->items()->sole();
        $this->assertSame(2, $item->quantity);
        $this->assertSame(self::SEAT_PRICE, $item->unit_price_cents, 'the unit price is a snapshot of the full-period price');
        $this->assertSame(1333, $item->amount_cents);
        $this->assertStringStartsWith('Extra seats x 2', $item->description);
        $this->assertStringContainsString('20 of 30 days', $item->description);
    }

    public function test_the_pro_rata_invoice_does_not_collide_with_the_period_invoice_even_at_the_same_instant(): void
    {
        $team = $this->makeOwner()->currentTeam;
        $plan = $this->makePlan(['extra_seat_price_monthly_cents' => 1000]);
        $sub = app(SubscriptionService::class)->subscribe($team, $plan, 'monthly');

        $this->seats()->change($sub, 1, 'k1');

        $this->assertSame(2, $this->invoices($sub)->count());
    }

    public function test_the_tax_rate_applies_to_the_pro_rata_invoice(): void
    {
        config(['billing.invoice.tax_rate_percent' => '15']);
        [, , $sub] = $this->scene();

        $this->seats()->change($sub, 2, 'k1');

        $invoice = $this->invoices($sub)->sole();
        $this->assertSame(1333, $invoice->subtotal_cents);
        $this->assertSame(200, $invoice->tax_cents, '15% of 1333 = 199.95, rounded half up');
        $this->assertSame(1533, $invoice->total_cents);
        $this->assertSame(1500, $invoice->tax_rate_bps);
    }

    public function test_an_unpaid_pro_rata_invoice_does_not_raise_the_entitlement(): void
    {
        [$team, , $sub] = $this->scene();

        $this->seats()->change($sub, 3, 'k1');

        $this->assertSame(10, $this->limit($team), 'plan seats only');
        $this->assertSame(0, $sub->fresh()->extra_seats);
    }

    public function test_a_partly_paid_invoice_does_not_raise_the_entitlement(): void
    {
        [$team, , $sub] = $this->scene();
        $change = $this->seats()->change($sub, 3, 'k1');
        $invoice = $change->invoice;

        app(RecordPayment::class)->apply($invoice, new Money(100, 'ZAR'));

        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame(10, $this->limit($team));
        $this->assertSame(BillingSeatChange::AWAITING_PAYMENT, $change->fresh()->status);
    }

    public function test_a_settled_invoice_raises_the_entitlement(): void
    {
        [$team, , $sub] = $this->scene();
        $change = $this->seats()->change($sub, 3, 'k1');

        $this->pay($change->invoice);

        $this->assertSame(3, $sub->fresh()->extra_seats);
        $this->assertSame(13, $this->limit($team));
        $this->assertSame(BillingSeatChange::APPLIED, $change->fresh()->status);
        $this->assertNotNull($change->fresh()->applied_at);
    }

    public function test_a_paystack_settlement_raises_the_entitlement_through_the_same_path(): void
    {
        $this->configurePaystack();
        $this->fakeHttp();
        [$team, , $sub] = $this->scene();
        $change = $this->seats()->change($sub, 2, 'k1');
        $attempt = $this->makeAttempt($change->invoice);

        $this->assertSame(10, $this->limit($team));

        $this->webhook($this->chargeSuccess($attempt))->assertOk();

        $this->assertSame('paid', $change->invoice->fresh()->status);
        $this->assertSame(2, $sub->fresh()->extra_seats);
        $this->assertSame(12, $this->limit($team));
    }

    public function test_a_replayed_paystack_delivery_does_not_apply_the_seats_twice(): void
    {
        $this->configurePaystack();
        $this->fakeHttp();
        [, , $sub] = $this->scene();
        $change = $this->seats()->change($sub, 2, 'k1');
        $attempt = $this->makeAttempt($change->invoice);

        $this->webhook($this->chargeSuccess($attempt))->assertOk();
        $this->webhook($this->chargeSuccess($attempt))->assertOk();

        $this->assertSame(2, $sub->fresh()->extra_seats);
        $this->assertSame(1, BillingAuditEvent::withoutGlobalScope('team')->where('action', 'seats.applied')->count());
    }

    public function test_adding_more_seats_builds_on_the_settled_count(): void
    {
        [$team, , $sub] = $this->scene();
        $this->pay($this->seats()->change($sub, 2, 'k1')->invoice);

        $second = $this->seats()->change($sub->fresh(), 5, 'k2');

        // Only the 3 additional seats are billed: 3 x 1000 x 20/30.
        $this->assertSame(2000, $second->invoice->total_cents);
        $this->assertSame(3, $second->invoice->items()->sole()->quantity);
        $this->assertSame(12, $this->limit($team));
        $this->pay($second->invoice);
        $this->assertSame(15, $this->limit($team));
    }

    public function test_a_second_purchase_waits_while_one_is_unpaid_and_a_voided_invoice_frees_it(): void
    {
        [, , $sub] = $this->scene();
        $first = $this->seats()->change($sub, 2, 'k1');

        $this->assertSame('seat_change_pending', $this->reason(fn () => $this->seats()->change($sub, 4, 'k2')));

        app(InvoiceService::class)->void($first->invoice, null, 'customer changed their mind');

        $this->assertSame(BillingSeatChange::LAPSED, $first->fresh()->status);
        $this->assertSame(0, $sub->fresh()->extra_seats);
        $this->seats()->change($sub, 4, 'k2');
        $this->assertSame(2, $this->invoices($sub)->count());
    }

    public function test_the_annual_cycle_uses_the_annual_price_over_the_annual_period(): void
    {
        [, , $sub] = $this->scene(sub: ['billing_cycle' => 'annual', 'current_period_start' => '2026-06-05 09:00:00', 'current_period_end' => '2027-06-05 09:00:00']);

        $this->seats()->change($sub, 1, 'k1');

        // 10000 per seat per year; 212 of 365 days left (5 Nov 2026 to 5 Jun 2027) = 5808.2 -> 5808.
        $invoice = $this->invoices($sub)->sole();
        $this->assertSame(intdiv(2 * 10000 * 212 + 365, 2 * 365), $invoice->total_cents);
        $this->assertSame(5808, $invoice->total_cents);
        $this->assertStringContainsString('212 of 365 days', $invoice->items()->sole()->description);
    }

    public function test_the_full_period_charge_can_be_chosen_in_config(): void
    {
        config(['billing.seats.add_charge' => 'full_period']);
        [, , $sub] = $this->scene();

        $this->seats()->change($sub, 2, 'k1');

        $this->assertSame(2000, $this->invoices($sub)->sole()->total_cents);
    }

    public function test_an_unknown_charge_mode_fails_loudly_and_charges_nothing(): void
    {
        config(['billing.seats.add_charge' => 'whatever']);
        [, , $sub] = $this->scene();

        try {
            $this->seats()->change($sub, 2, 'k1');
            $this->fail('Expected a LogicException.');
        } catch (\LogicException) {
            $this->assertSame(0, $this->invoices($sub)->count());
        }
    }

    public function test_money_rounding_is_half_up_on_whole_cents(): void
    {
        $this->assertSame(1333, (new Money(2000, 'ZAR'))->proRata(20, 30)->cents, '1333.33 rounds down');
        $this->assertSame(1, (new Money(1, 'ZAR'))->proRata(1, 2)->cents, '0.5 rounds up');
        $this->assertSame(0, (new Money(1, 'ZAR'))->proRata(1, 3)->cents, '0.33 rounds down');
        $this->assertSame(2, (new Money(1, 'ZAR'))->proRata(3, 2)->cents, '1.5 rounds up');
        $this->assertSame(667, (new Money(1001, 'ZAR'))->proRata(2, 3)->cents, '667.33');
        $this->assertSame(668, (new Money(1002, 'ZAR'))->proRata(2, 3)->cents, 'exactly 668');
        $this->assertSame(1000, (new Money(1000, 'ZAR'))->proRata(30, 30)->cents, 'a whole period is the full price');
        $this->assertSame(0, (new Money(1000, 'ZAR'))->proRata(0, 30)->cents);
        $this->assertSame(3000, (new Money(1000, 'ZAR'))->times(3)->cents);
    }

    // ---- reducing: deferred, no refund ----------------------------------------------------------------

    private function withSettledSeats(int $seats): array
    {
        [$team, $plan, $sub] = $this->scene(sub: ['extra_seats' => $seats]);

        return [$team, $plan, $sub];
    }

    public function test_reducing_seats_is_scheduled_and_changes_nothing_now(): void
    {
        [$team, , $sub] = $this->withSettledSeats(5);

        $change = $this->seats()->change($sub, 2, 'k1');

        $sub->refresh();
        $this->assertSame(5, $sub->extra_seats);
        $this->assertSame(2, $sub->pending_extra_seats);
        $this->assertSame(15, $this->limit($team), 'the paid-for seats stay until the period ends');
        $this->assertSame(BillingSeatChange::SCHEDULED, $change->status);
        $this->assertSame(0, $this->invoices($sub)->count(), 'no invoice, no credit note, no refund');
        $this->assertSame(0, DB::table('billing_payments')->count());
    }

    public function test_a_scheduled_reduction_is_applied_at_renewal_and_the_renewal_invoice_bills_the_lower_count(): void
    {
        [$team, , $sub] = $this->withSettledSeats(5);
        $change = $this->seats()->change($sub, 2, 'k1');

        Carbon::setTestNow('2026-11-25 09:00:01');
        app(SubscriptionLifecycle::class)->run();

        $sub->refresh();
        $this->assertSame(2, $sub->extra_seats);
        $this->assertNull($sub->pending_extra_seats);
        $this->assertSame(12, $this->limit($team));
        $this->assertSame(BillingSeatChange::APPLIED, $change->fresh()->status);

        $renewal = $this->invoices($sub)->sole();
        $this->assertSame(29900 + 2 * self::SEAT_PRICE, $renewal->total_cents);
        $lines = $renewal->items()->orderBy('id')->get();
        $this->assertSame('Extra seats x 2', $lines[1]->description);
    }

    public function test_the_last_request_before_renewal_wins_and_asking_for_the_current_count_cancels_the_reduction(): void
    {
        [$team, , $sub] = $this->withSettledSeats(5);
        $first = $this->seats()->change($sub, 2, 'k1');
        $this->seats()->change($sub, 3, 'k2');

        $this->assertSame(3, $sub->fresh()->pending_extra_seats);
        $this->assertSame(BillingSeatChange::SUPERSEDED, $first->fresh()->status);

        $this->seats()->change($sub, 5, 'k3');
        $this->assertNull($sub->fresh()->pending_extra_seats);

        Carbon::setTestNow('2026-11-25 09:00:01');
        app(SubscriptionLifecycle::class)->run();
        $this->assertSame(5, $sub->fresh()->extra_seats);
        $this->assertSame(0, BillingAuditEvent::withoutGlobalScope('team')->where('action', 'seats.reduction_applied')->count());
    }

    public function test_reducing_to_zero_is_allowed_even_if_the_plan_no_longer_has_a_price(): void
    {
        [, $plan, $sub] = $this->withSettledSeats(2);
        $plan->update(['extra_seat_price_monthly_cents' => null]);

        $this->seats()->change($sub, 0, 'k1');

        $this->assertSame(0, $sub->fresh()->pending_extra_seats);
    }

    public function test_seats_are_dropped_at_renewal_when_the_plan_no_longer_sells_them(): void
    {
        [$team, $plan, $sub] = $this->withSettledSeats(4);
        $plan->update(['extra_seat_price_monthly_cents' => null]);

        Carbon::setTestNow('2026-11-25 09:00:01');
        app(SubscriptionLifecycle::class)->run();

        $this->assertSame(0, $sub->fresh()->extra_seats);
        $this->assertSame(10, $this->limit($team));
        $this->assertSame(29900, $this->invoices($sub)->sole()->total_cents, 'seats are never billed or carried without a price');
    }

    public function test_a_queued_plan_change_to_a_plan_without_a_seat_price_drops_the_seats_at_renewal(): void
    {
        [$team, , $sub] = $this->withSettledSeats(4);
        $other = $this->makePlan(['seat_limit' => 20, 'price_monthly_cents' => 49900]);
        $sub->forceFill(['pending_plan_id' => $other->id, 'pending_billing_cycle' => 'monthly'])->save();

        Carbon::setTestNow('2026-11-25 09:00:01');
        app(SubscriptionLifecycle::class)->run();

        $this->assertSame(0, $sub->fresh()->extra_seats);
        $this->assertSame(20, $this->limit($team));
        $this->assertSame(49900, $this->invoices($sub)->sole()->total_cents);
    }

    public function test_an_unpaid_purchase_lapses_at_renewal_and_its_invoice_is_voided(): void
    {
        [$team, , $sub] = $this->scene();
        $change = $this->seats()->change($sub, 3, 'k1');

        Carbon::setTestNow('2026-11-25 09:00:01');
        app(SubscriptionLifecycle::class)->run();

        $this->assertSame(BillingSeatChange::LAPSED, $change->fresh()->status);
        $this->assertSame('void', $change->invoice->fresh()->status);
        $this->assertSame(0, $sub->fresh()->extra_seats);
        $this->assertSame(10, $this->limit($team));
    }

    // ---- idempotency ----------------------------------------------------------------------------------

    public function test_the_same_request_key_twice_changes_and_invoices_once(): void
    {
        [, , $sub] = $this->scene();

        $first = $this->seats()->change($sub, 2, 'req-1');
        $second = $this->seats()->change($sub, 2, 'req-1');

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->invoices($sub)->count());
        $this->assertSame(1, BillingSeatChange::withoutGlobalScope('team')->count());
        $this->assertSame(1, BillingAuditEvent::withoutGlobalScope('team')->where('action', 'seats.add_invoiced')->count());
    }

    public function test_a_replay_still_works_after_the_seats_were_paid_for(): void
    {
        [, , $sub] = $this->scene();
        $first = $this->seats()->change($sub, 2, 'req-1');
        $this->pay($first->invoice);

        $again = $this->seats()->change($sub->fresh(), 2, 'req-1');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $this->invoices($sub)->count());
        $this->assertSame(2, $sub->fresh()->extra_seats);
    }

    public function test_a_key_reused_for_a_different_count_is_a_conflict(): void
    {
        [, , $sub] = $this->scene();
        $this->seats()->change($sub, 2, 'req-1');

        $this->assertSame('idempotency_conflict', $this->reason(fn () => $this->seats()->change($sub, 3, 'req-1')));
        $this->assertSame(1, $this->invoices($sub)->count());
    }

    public function test_keys_are_scoped_to_the_subscription(): void
    {
        [, , $a] = $this->scene();
        [, , $b] = $this->scene();

        $this->seats()->change($a, 1, 'same-key');
        $this->seats()->change($b, 1, 'same-key');

        $this->assertSame(1, $this->invoices($a)->count());
        $this->assertSame(1, $this->invoices($b)->count());
    }

    public function test_a_refused_request_leaves_no_trace_and_can_be_retried_with_the_same_key(): void
    {
        [, $plan, $sub] = $this->scene(withPrice: false);

        $this->reason(fn () => $this->seats()->change($sub, 2, 'req-1'));
        $this->assertSame(0, BillingSeatChange::withoutGlobalScope('team')->count());

        $plan->update(['extra_seat_price_monthly_cents' => 1000]);
        $this->seats()->change($sub->fresh(), 2, 'req-1');
        $this->assertSame(1, $this->invoices($sub)->count());
    }

    // ---- invoices for subscriptions without extra seats stay exactly as they were -----------------------

    public function test_the_period_invoice_for_zero_extra_seats_is_unchanged_even_when_the_plan_sells_seats(): void
    {
        config(['billing.invoice.tax_rate_percent' => '15']);
        [, , $sub] = $this->scene(sub: ['current_period_start' => now(), 'current_period_end' => now()->addMonth()]);

        $invoice = app(InvoiceGenerator::class)->generateForCurrentPeriod($sub->load('plan'));

        $this->assertSame(1, $invoice->items()->count());
        $item = $invoice->items()->sole();
        $this->assertStringContainsString('plan, monthly subscription', $item->description);
        $this->assertSame([1, 29900, 29900], [$item->quantity, $item->unit_price_cents, $item->amount_cents]);
        $this->assertSame(29900, $invoice->subtotal_cents);
        $this->assertSame(4485, $invoice->tax_cents);
        $this->assertSame(34385, $invoice->total_cents);
    }

    public function test_the_period_invoice_adds_an_extra_seats_line_and_includes_it_in_subtotal_tax_and_total(): void
    {
        config(['billing.invoice.tax_rate_percent' => '15']);
        [, , $sub] = $this->scene(sub: ['current_period_start' => now(), 'current_period_end' => now()->addMonth(), 'extra_seats' => 3]);

        $invoice = app(InvoiceGenerator::class)->generateForCurrentPeriod($sub->load('plan'));

        $items = $invoice->items()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame([1, 29900, 29900], [$items[0]->quantity, $items[0]->unit_price_cents, $items[0]->amount_cents]);
        $this->assertSame('Extra seats x 3', $items[1]->description);
        $this->assertSame([3, 1000, 3000], [$items[1]->quantity, $items[1]->unit_price_cents, $items[1]->amount_cents]);
        $this->assertSame(32900, $invoice->subtotal_cents);
        $this->assertSame(4935, $invoice->tax_cents);
        $this->assertSame(37835, $invoice->total_cents);
        $this->assertSame($items[0]->period, $items[1]->period);
    }

    public function test_the_extra_seat_line_is_a_snapshot_that_later_price_edits_do_not_touch(): void
    {
        [, $plan, $sub] = $this->scene(sub: ['current_period_start' => now(), 'current_period_end' => now()->addMonth(), 'extra_seats' => 2]);
        $invoice = app(InvoiceGenerator::class)->generateForCurrentPeriod($sub->load('plan'));

        $plan->update(['extra_seat_price_monthly_cents' => 9999]);

        $this->assertSame(1000, $invoice->items()->orderBy('id')->get()[1]->unit_price_cents);
    }

    public function test_extra_seats_are_not_billed_when_the_plan_has_no_price_for_the_cycle(): void
    {
        [, , $sub] = $this->scene(['extra_seat_price_monthly_cents' => null], ['current_period_start' => now(), 'current_period_end' => now()->addMonth(), 'extra_seats' => 3], withPrice: false);

        $invoice = app(InvoiceGenerator::class)->generateForCurrentPeriod($sub->load('plan'));

        $this->assertSame(29900, $invoice->total_cents);
        $this->assertSame(1, $invoice->items()->count());
    }

    public function test_a_free_plan_with_paid_extra_seats_is_invoiced_for_the_seats_only(): void
    {
        [, , $sub] = $this->scene(['price_monthly_cents' => 0, 'price_annual_cents' => 0], ['current_period_start' => now(), 'current_period_end' => now()->addMonth(), 'extra_seats' => 2]);

        $invoice = app(InvoiceGenerator::class)->generateForCurrentPeriod($sub->load('plan'));

        $this->assertSame(2000, $invoice->total_cents);
        $this->assertSame('Extra seats x 2', $invoice->items()->sole()->description);
    }

    public function test_a_free_plan_with_no_extra_seats_is_still_not_invoiced(): void
    {
        [, , $sub] = $this->scene(['price_monthly_cents' => 0, 'price_annual_cents' => 0], ['current_period_start' => now(), 'current_period_end' => now()->addMonth()]);

        $this->assertNull(app(InvoiceGenerator::class)->generateForCurrentPeriod($sub->load('plan')));
    }

    // ---- entitlement -----------------------------------------------------------------------------------

    public function test_the_entitlement_reports_plan_seats_plus_settled_extra_seats_and_the_count_separately(): void
    {
        [$team, , $sub] = $this->scene(sub: ['extra_seats' => 4]);
        $this->linkToInfoDot($team);

        $e = app(EntitlementResolver::class)->forTeam($team->fresh());

        $this->assertSame(14, $e['seat_limit']);
        $this->assertSame(4, $e['extra_seats']);
        $this->assertSame('active', $e['status']);
        $this->assertSame(['api_access'], $e['features']);
        $this->assertSame(50, $e['storage_gb']);
    }

    public function test_an_unlimited_plan_stays_unlimited(): void
    {
        [$team] = $this->scene(['seat_limit' => null], ['extra_seats' => 4]);

        $e = app(EntitlementResolver::class)->forTeam($team);

        $this->assertNull($e['seat_limit']);
        $this->assertSame(4, $e['extra_seats']);
    }

    public function test_without_a_live_subscription_the_free_tier_applies_and_extra_seats_is_zero(): void
    {
        config(['billing.free_tier' => ['seat_limit' => 3, 'storage_gb' => 1, 'features' => []]]);
        [$team, , $sub] = $this->scene(sub: ['status' => 'canceled', 'ended_at' => now()->subDay(), 'extra_seats' => 4]);

        $e = app(EntitlementResolver::class)->forTeam($team);

        $this->assertSame(3, $e['seat_limit']);
        $this->assertSame(0, $e['extra_seats']);
    }

    // ---- audit ----------------------------------------------------------------------------------------

    public function test_every_seat_change_is_recorded_in_the_audit_log(): void
    {
        [$team, , $sub] = $this->scene();
        $actor = $this->makeOwner();

        $change = $this->seats()->change($sub, 2, 'k1', $actor);
        $this->pay($change->invoice);
        $this->seats()->change($sub->fresh(), 1, 'k2', $actor);

        $actions = BillingAuditEvent::withoutGlobalScope('team')->where('team_id', $team->id)->orderBy('id')->pluck('action')->all();
        foreach (['seats.add_invoiced', 'invoice.generated', 'payment.recorded', 'invoice.paid', 'seats.applied', 'seats.reduction_scheduled'] as $expected) {
            $this->assertContains($expected, $actions);
        }

        $added = BillingAuditEvent::withoutGlobalScope('team')->where('action', 'seats.add_invoiced')->sole();
        $this->assertSame($actor->id, $added->actor_id);
        $this->assertSame('subscription', $added->subject_type);
        $this->assertSame($sub->id, $added->subject_id);
        $this->assertSame([0, 2], [$added->meta['from'], $added->meta['to']]);
    }
}
