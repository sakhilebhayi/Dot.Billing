<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class DashboardMoneyTest extends TestCase
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

    public function test_paid_totals_are_listed_per_currency_and_never_summed_across_them(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $paid = ['status' => 'paid', 'paid_at' => now()->subDays(3)];
        $this->makeInvoice($team, $paid + ['currency' => 'ZAR', 'total_cents' => 12345678, 'amount_paid_cents' => 12345678]);
        $this->makeInvoice($team, $paid + ['currency' => 'ZAR', 'total_cents' => 100, 'amount_paid_cents' => 100]);
        $this->makeInvoice($team, $paid + ['currency' => 'USD', 'total_cents' => 765432, 'amount_paid_cents' => 765432]);

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertOk()
            ->assertSee('ZAR 123,457.78')
            ->assertSee('USD 7,654.32')
            ->assertDontSee('131,112.10');
        $this->assertSame(['USD', 'ZAR'], array_keys($response->viewData('paidYtdByCurrency')));
    }

    public function test_only_this_years_payments_count_and_other_teams_never_leak(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $other = $this->makeOwner();
        $this->makeInvoice($team, ['status' => 'paid', 'paid_at' => '2025-12-30 10:00:00', 'total_cents' => 55555555, 'amount_paid_cents' => 55555555]);
        $this->makeInvoice($other->currentTeam, ['status' => 'paid', 'paid_at' => now(), 'total_cents' => 77777777, 'amount_paid_cents' => 77777777]);

        $response = $this->actingAs($owner)->get('/dashboard');

        // The invoice list below the readout legitimately shows last year's invoice, so assert on the data.
        $this->assertSame([], $response->viewData('paidYtdByCurrency'));
    }

    public function test_the_outstanding_balance_is_per_currency_and_counts_open_invoices_only_by_what_is_unpaid(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $this->makeInvoice($team, ['currency' => 'ZAR', 'total_cents' => 100000, 'amount_paid_cents' => 25000]);
        $this->makeInvoice($team, ['currency' => 'USD', 'total_cents' => 40404]);
        $this->makeInvoice($team, ['currency' => 'ZAR', 'status' => 'draft', 'total_cents' => 999999, 'issued_at' => null, 'due_date' => null]);
        $this->makeInvoice($team, ['currency' => 'ZAR', 'status' => 'void', 'total_cents' => 888888]);

        $response = $this->actingAs($owner)->get('/dashboard');

        $outstanding = $response->viewData('outstandingByCurrency');
        $this->assertSame(75000, $outstanding['ZAR']->cents);
        $this->assertSame(40404, $outstanding['USD']->cents);
        $response->assertSee('ZAR 750.00')->assertSee('USD 404.04');
    }

    public function test_the_dashboard_says_plainly_what_needs_attention(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $this->makeInvoice($team, ['due_date' => now()->subDays(4)]);

        $this->actingAs($owner)->get('/dashboard')->assertSee('1 invoice is overdue');
    }

    public function test_a_quiet_account_says_nothing_needs_attention(): void
    {
        $this->actingAs($this->makeOwner())->get('/dashboard')->assertSee('Nothing needs your attention.');
    }
}
