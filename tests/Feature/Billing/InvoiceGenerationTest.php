<?php

namespace Tests\Feature\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class InvoiceGenerationTest extends TestCase
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

    private function invoicesOf($team)
    {
        return BillingInvoice::withoutGlobalScope('team')->where('team_id', $team->id)->orderBy('id')->get();
    }

    public function test_subscribing_to_a_paid_plan_raises_the_first_invoice_at_once(): void
    {
        $owner = $this->makeOwner();
        $plan = $this->makePlan(['name' => 'Pro', 'price_monthly_cents' => 29900]);

        $sub = app(SubscriptionService::class)->subscribe($owner->currentTeam, $plan, 'monthly', $owner);

        $invoice = $this->invoicesOf($owner->currentTeam)->sole();
        $this->assertSame($sub->id, $invoice->subscription_id);
        $this->assertSame('open', $invoice->status);
        $this->assertSame('INV-2026-0001', $invoice->invoice_number);
        $this->assertSame(29900, $invoice->total_cents);
        $this->assertSame('ZAR', $invoice->currency);
        $this->assertTrue($invoice->period_start->equalTo($sub->current_period_start));
        $this->assertTrue($invoice->period_end->equalTo($sub->current_period_end));
        $this->assertTrue($invoice->issued_at->equalTo(now()));
    }

    public function test_the_due_date_comes_from_the_configured_payment_terms(): void
    {
        config(['billing.invoice.payment_terms_days' => 30]);
        $owner = $this->makeOwner();

        app(SubscriptionService::class)->subscribe($owner->currentTeam, $this->makePlan(), 'monthly', $owner);

        $this->assertTrue($this->invoicesOf($owner->currentTeam)->sole()->due_date->equalTo('2026-12-05 09:00:00'));
    }

    public function test_line_items_are_snapshots_that_later_plan_edits_do_not_touch(): void
    {
        $owner = $this->makeOwner();
        $plan = $this->makePlan(['name' => 'Starter', 'price_monthly_cents' => 9900]);
        app(SubscriptionService::class)->subscribe($owner->currentTeam, $plan, 'monthly', $owner);

        $plan->update(['name' => 'Renamed', 'price_monthly_cents' => 99900]);

        $item = $this->invoicesOf($owner->currentTeam)->sole()->items()->sole();
        $this->assertStringContainsString('Starter', $item->description);
        $this->assertSame(1, $item->quantity);
        $this->assertSame(9900, $item->unit_price_cents);
        $this->assertSame(9900, $item->amount_cents);
        $this->assertSame('5 Nov 2026 to 5 Dec 2026', $item->period);
    }

    public function test_tax_is_taken_from_config_in_integer_cents_and_the_rate_is_recorded(): void
    {
        config(['billing.invoice.tax_rate_percent' => '15']);
        $owner = $this->makeOwner();
        app(SubscriptionService::class)->subscribe($owner->currentTeam, $this->makePlan(['price_monthly_cents' => 9999]), 'monthly', $owner);

        $invoice = $this->invoicesOf($owner->currentTeam)->sole();

        $this->assertSame(9999, $invoice->subtotal_cents);
        $this->assertSame(1500, $invoice->tax_cents); // 1499.85 rounds half up
        $this->assertSame(11499, $invoice->total_cents);
        $this->assertSame(1500, $invoice->tax_rate_bps);
    }

    public function test_tax_defaults_to_nothing(): void
    {
        $owner = $this->makeOwner();
        app(SubscriptionService::class)->subscribe($owner->currentTeam, $this->makePlan(), 'monthly', $owner);

        $invoice = $this->invoicesOf($owner->currentTeam)->sole();

        $this->assertSame(0, $invoice->tax_cents);
        $this->assertSame($invoice->subtotal_cents, $invoice->total_cents);
    }

    public function test_an_annual_subscription_is_invoiced_at_the_annual_price(): void
    {
        $owner = $this->makeOwner();
        app(SubscriptionService::class)->subscribe($owner->currentTeam, $this->makePlan(['price_annual_cents' => 250000]), 'annual', $owner);

        $this->assertSame(250000, $this->invoicesOf($owner->currentTeam)->sole()->total_cents);
    }

    public function test_free_plans_and_trials_are_never_invoiced(): void
    {
        $free = $this->makeOwner();
        app(SubscriptionService::class)->subscribe($free->currentTeam, $this->makePlan(['price_monthly_cents' => 0, 'price_annual_cents' => 0]), 'monthly', $free);

        $trial = $this->makeOwner();
        app(SubscriptionService::class)->subscribe($trial->currentTeam, $this->makePlan(['trial_days' => 14]), 'monthly', $trial);

        $this->artisan('billing:generate-invoices')->assertSuccessful();

        $this->assertCount(0, $this->invoicesOf($free->currentTeam));
        $this->assertCount(0, $this->invoicesOf($trial->currentTeam));
    }

    public function test_the_command_creates_the_missing_invoice_and_is_idempotent(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $this->makeSubscription($team, $this->makePlan(['price_monthly_cents' => 5000]), [
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addDays(27),
        ]);

        $this->artisan('billing:generate-invoices')->expectsOutputToContain('Created 1 invoice')->assertSuccessful();
        $this->artisan('billing:generate-invoices')->expectsOutputToContain('Created 0 invoice')->assertSuccessful();

        $this->assertCount(1, $this->invoicesOf($team));
    }

    public function test_the_command_skips_canceled_subscriptions_and_other_teams_are_unaffected(): void
    {
        $a = $this->makeOwner();
        $b = $this->makeOwner();
        $plan = $this->makePlan();
        $this->makeSubscription($a->currentTeam, $plan, ['status' => 'canceled']);
        $this->makeSubscription($b->currentTeam, $plan);

        $this->artisan('billing:generate-invoices')->assertSuccessful();

        $this->assertCount(0, $this->invoicesOf($a->currentTeam));
        $this->assertCount(1, $this->invoicesOf($b->currentTeam));
    }

    public function test_an_archived_plan_still_bills_the_teams_already_on_it(): void
    {
        $owner = $this->makeOwner();
        $plan = $this->makePlan(['is_active' => false]);
        $this->makeSubscription($owner->currentTeam, $plan);

        $this->artisan('billing:generate-invoices')->assertSuccessful();

        $this->assertCount(1, $this->invoicesOf($owner->currentTeam));
    }

    public function test_the_database_stops_two_invoices_for_one_subscription_period(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan());
        $this->makeInvoice($owner->currentTeam, ['subscription_id' => $sub->id, 'period_start' => $sub->current_period_start]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeInvoice($owner->currentTeam, ['subscription_id' => $sub->id, 'period_start' => $sub->current_period_start]);
    }

    public function test_the_generator_returns_null_for_a_period_that_already_has_an_invoice(): void
    {
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan());
        $generator = app(InvoiceGenerator::class);

        $this->assertNotNull($generator->generateForCurrentPeriod($sub));
        $this->assertNull($generator->generateForCurrentPeriod($sub->fresh()));
    }

    public function test_with_auto_finalize_off_invoices_wait_in_draft_until_finalized(): void
    {
        config(['billing.invoice.auto_finalize' => false]);
        $owner = $this->makeOwner();
        $sub = $this->makeSubscription($owner->currentTeam, $this->makePlan());

        $draft = app(InvoiceGenerator::class)->generateForCurrentPeriod($sub);

        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->issued_at);
        $this->assertNull($draft->due_date);

        $open = app(InvoiceService::class)->finalize($draft, $owner);

        $this->assertSame('open', $open->status);
        $this->assertTrue($open->issued_at->equalTo(now()));
        $this->assertTrue($open->due_date->equalTo(now()->addDays(14)));
    }

    public function test_only_a_draft_can_be_finalized(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam);

        try {
            app(InvoiceService::class)->finalize($invoice, $owner);
            $this->fail('expected a violation');
        } catch (BillingRuleViolation $e) {
            $this->assertSame('invalid_state', $e->reason);
        }
    }

    public function test_an_unpaid_invoice_can_be_voided_and_the_audit_says_who(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam);

        $void = app(InvoiceService::class)->void($invoice, $owner, 'Raised in error');

        $this->assertSame('void', $void->status);
        $this->assertTrue($void->voided_at->equalTo(now()));
        $this->assertDatabaseHas('billing_audit_events', ['action' => 'invoice.voided', 'actor_id' => $owner->id, 'subject_id' => $invoice->id]);
    }

    public function test_a_paid_or_partly_paid_invoice_cannot_be_voided(): void
    {
        $owner = $this->makeOwner();
        $paid = $this->makeInvoice($owner->currentTeam, ['status' => 'paid', 'amount_paid_cents' => 50000, 'paid_at' => now()]);
        $partly = $this->makeInvoice($owner->currentTeam, ['amount_paid_cents' => 100]);

        foreach ([$paid, $partly] as $invoice) {
            try {
                app(InvoiceService::class)->void($invoice, $owner, 'oops');
                $this->fail('expected a violation');
            } catch (BillingRuleViolation $e) {
                $this->assertSame('invalid_state', $e->reason);
            }
        }
    }

    public function test_generation_never_creates_a_payment(): void
    {
        $owner = $this->makeOwner();
        $this->makeSubscription($owner->currentTeam, $this->makePlan());

        $this->artisan('billing:generate-invoices')->assertSuccessful();

        $this->assertSame(0, BillingPayment::withoutGlobalScope('team')->count());
    }

    public function test_the_generation_and_advance_commands_are_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' '.$e->expression);

        $this->assertTrue($events->contains(fn ($e) => str_contains($e, 'billing:generate-invoices') && str_ends_with($e, ' * * *')), $events->implode("\n"));
        $this->assertTrue($events->contains(fn ($e) => str_contains($e, 'billing:advance-subscriptions') && str_ends_with($e, ' * * *')), $events->implode("\n"));
    }
}
