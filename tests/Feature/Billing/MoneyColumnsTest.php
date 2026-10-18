<?php

namespace Tests\Feature\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class MoneyColumnsTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_19_100000_add_minor_unit_columns.php');
    }

    public function test_the_migration_backfills_cents_from_legacy_decimal_rows_and_is_reversible(): void
    {
        $team = Team::factory()->create();
        $migration = $this->migration();

        // Put the schema back to what production has today, then insert rows the old way.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('billing_invoices', 'total_cents'));

        $now = now()->toDateTimeString();
        DB::table('billing_plans')->insert([
            'name' => 'Legacy Pro', 'slug' => 'legacy-pro-plan', 'price_monthly' => '299.00', 'price_annual' => '2990.50',
            'currency' => 'ZAR', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $invoiceId = DB::table('billing_invoices')->insertGetId([
            'team_id' => $team->id, 'invoice_number' => 'LEGACY-0001', 'status' => 'open',
            'subtotal' => '0.29', 'tax' => '19.99', 'total' => '1234567.89', 'currency' => 'ZAR',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('billing_invoice_items')->insert([
            'invoice_id' => $invoiceId, 'description' => 'Seats', 'quantity' => 3,
            'unit_price' => '0.07', 'amount' => '0.21', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('billing_payments')->insert([
            'team_id' => $team->id, 'invoice_id' => $invoiceId, 'amount' => '100.10', 'currency' => 'USD',
            'status' => 'succeeded', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $migration->up();

        $plan = DB::table('billing_plans')->where('slug', 'legacy-pro-plan')->first();
        $this->assertSame(29900, (int) $plan->price_monthly_cents);
        $this->assertSame(299050, (int) $plan->price_annual_cents);

        $invoice = DB::table('billing_invoices')->find($invoiceId);
        $this->assertSame(29, (int) $invoice->subtotal_cents);
        $this->assertSame(1999, (int) $invoice->tax_cents);
        $this->assertSame(123456789, (int) $invoice->total_cents);

        $item = DB::table('billing_invoice_items')->where('invoice_id', $invoiceId)->first();
        $this->assertSame(7, (int) $item->unit_price_cents);
        $this->assertSame(21, (int) $item->amount_cents);

        $this->assertSame(10010, (int) DB::table('billing_payments')->where('invoice_id', $invoiceId)->value('amount_cents'));
    }

    public function test_the_down_migration_leaves_the_legacy_decimals_untouched(): void
    {
        $team = Team::factory()->create();
        $invoice = BillingInvoice::create(['team_id' => $team->id, 'invoice_number' => 'KEEP-1', 'status' => 'open', 'total_cents' => 12345, 'subtotal_cents' => 12345, 'currency' => 'ZAR']);

        $this->migration()->down();

        $this->assertSame('123.45', number_format((float) DB::table('billing_invoices')->find($invoice->id)->total, 2, '.', ''));
    }

    public function test_writing_cents_mirrors_into_the_legacy_decimal(): void
    {
        $team = Team::factory()->create();

        $invoice = BillingInvoice::create([
            'team_id' => $team->id, 'invoice_number' => 'MIRROR-1', 'status' => 'open', 'currency' => 'ZAR',
            'subtotal_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000,
        ]);

        $fresh = $invoice->fresh();
        $this->assertSame(115000, $fresh->total_cents);
        $this->assertSame('1150.00', $fresh->total);
        $this->assertSame('150.00', $fresh->tax);

        $fresh->update(['total_cents' => 115001]);
        $this->assertSame('1150.01', $fresh->fresh()->total);
    }

    public function test_old_code_that_still_writes_decimals_keeps_the_cents_in_step(): void
    {
        $team = Team::factory()->create();

        $invoice = BillingInvoice::create(['team_id' => $team->id, 'invoice_number' => 'OLDWAY-1', 'status' => 'open', 'total' => 299.00]);
        $this->assertSame(29900, $invoice->fresh()->total_cents);

        $invoice->update(['total' => '0.29']);
        $this->assertSame(29, $invoice->fresh()->total_cents);
    }

    public function test_cents_win_when_both_representations_are_written(): void
    {
        $team = Team::factory()->create();

        $invoice = BillingInvoice::create(['team_id' => $team->id, 'invoice_number' => 'BOTH-1', 'status' => 'open', 'total' => 1, 'total_cents' => 5000]);

        $this->assertSame(5000, $invoice->fresh()->total_cents);
        $this->assertSame('50.00', $invoice->fresh()->total);
    }

    public function test_items_payments_and_plans_mirror_too(): void
    {
        $team = Team::factory()->create();
        $invoice = $this->makeInvoice($team);

        $item = BillingInvoiceItem::create(['invoice_id' => $invoice->id, 'description' => 'Seats', 'quantity' => 3, 'unit_price_cents' => 7, 'amount_cents' => 21]);
        $this->assertSame('0.07', $item->fresh()->unit_price);
        $this->assertSame('0.21', $item->fresh()->amount);

        $payment = BillingPayment::create(['team_id' => $team->id, 'invoice_id' => $invoice->id, 'amount_cents' => 1999, 'currency' => 'ZAR', 'status' => 'succeeded']);
        $this->assertSame('19.99', $payment->fresh()->amount);

        $plan = BillingPlan::create(['name' => 'X', 'slug' => 'mirror-plan', 'price_monthly_cents' => 9900, 'price_annual_cents' => 99000]);
        $this->assertSame('99.00', $plan->fresh()->price_monthly);
        $this->assertSame('990.00', $plan->fresh()->price_annual);
    }

    public function test_money_helpers_return_value_objects_in_the_rows_own_currency(): void
    {
        $team = Team::factory()->create();
        $invoice = $this->makeInvoice($team, ['currency' => 'USD', 'total_cents' => 123450]);

        $this->assertSame('USD 1,234.50', $invoice->totalMoney()->format());
        $this->assertSame('USD 0.00', $invoice->taxMoney()->format());
        $this->assertSame('USD 1,234.50', $invoice->balanceMoney()->format());
    }

    public function test_a_plan_created_without_naming_every_column_is_active_and_untrialed_straight_away(): void
    {
        $plan = BillingPlan::create(['name' => 'Bare', 'slug' => 'bare-plan', 'price_monthly_cents' => 100, 'price_annual_cents' => 1000]);

        $this->assertTrue($plan->is_active);
        $this->assertFalse($plan->isArchived());
        $this->assertSame(0, $plan->trial_days);
        $this->assertSame('ZAR', $plan->currency);
    }

    public function test_a_plan_is_free_when_its_price_in_cents_is_zero(): void
    {
        $this->assertTrue($this->makePlan(['price_monthly_cents' => 0, 'price_annual_cents' => 0])->isFree());
        $this->assertFalse($this->makePlan()->isFree());
    }
}
