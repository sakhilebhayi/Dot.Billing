<?php

namespace Tests\Feature\Billing;

use App\Models\Team;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class DunningMigrationTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_19_120200_add_grace_and_uniqueness_to_dunning_cases.php');
    }

    public function test_legacy_duplicate_cases_are_kept_but_de_duplicated_with_a_note(): void
    {
        $migration = $this->migration();
        $team = Team::factory()->create();
        $invoice = $this->makeInvoice($team);
        $payment = DB::table('billing_payments')->insertGetId(['team_id' => $team->id, 'amount' => 1, 'amount_cents' => 100, 'currency' => 'ZAR', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);

        $migration->down();
        $now = now()->toDateTimeString();
        $case = fn (array $a) => DB::table('dunning_cases')->insertGetId($a + ['team_id' => $team->id, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        $first = $case(['invoice_id' => $invoice->id, 'reason' => 'invoice_overdue']);
        $dupeA = $case(['invoice_id' => $invoice->id, 'reason' => 'invoice_overdue']);
        $dupeB = $case(['invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'dismissed']);
        $failedFirst = $case(['invoice_id' => $invoice->id, 'payment_id' => $payment, 'reason' => 'payment_failed']);
        $failedDupe = $case(['invoice_id' => $invoice->id, 'payment_id' => $payment, 'reason' => 'payment_failed']);

        $migration->up();

        $this->assertSame(5, DB::table('dunning_cases')->count(), 'no row is ever deleted');
        $row = fn (int $id) => DB::table('dunning_cases')->find($id);

        $this->assertSame($invoice->id, (int) $row($first)->invoice_id);
        $this->assertNull($row($dupeA)->invoice_id);
        $this->assertNull($row($dupeB)->invoice_id);
        $this->assertStringContainsString("duplicate of case #{$first}", $row($dupeA)->resolution_notes);
        $this->assertStringContainsString("invoice #{$invoice->id}", $row($dupeA)->resolution_notes);
        $this->assertSame('dismissed', $row($dupeB)->status, 'a legacy status is never rewritten');

        $this->assertSame($payment, (int) $row($failedFirst)->payment_id);
        $this->assertNull($row($failedDupe)->payment_id);
        $this->assertSame($invoice->id, (int) $row($failedDupe)->invoice_id, 'the invoice link of a payment case is not what is deduplicated');
    }

    public function test_after_the_migration_duplicates_are_refused_by_the_database(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            $this->markTestSkipped('Partial unique index: sqlite and pgsql only.');
        }

        $team = Team::factory()->create();
        $invoice = $this->makeInvoice($team);
        $row = ['team_id' => $team->id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()];
        DB::table('dunning_cases')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('dunning_cases')->insert($row);
    }

    public function test_the_migration_adds_the_grace_column_and_rolls_back_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumn('dunning_cases', 'grace_until'));

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('dunning_cases', 'grace_until'));
    }
}
