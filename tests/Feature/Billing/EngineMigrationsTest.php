<?php

namespace Tests\Feature\Billing;

use App\Models\Team;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The engine migrations run against data that already exists in production. Each test rolls the
 * migration back to the old shape, writes rows the way the old app did, and runs it forward again.
 */
class EngineMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require database_path('migrations/'.$file);
    }

    private function planId(): int
    {
        return DB::table('billing_plans')->insertGetId([
            'name' => 'Legacy', 'slug' => 'legacy-'.uniqid(), 'price_monthly' => 10, 'price_annual' => 100,
            'currency' => 'ZAR', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function subscription(int $teamId, int $planId, string $status): int
    {
        return DB::table('billing_subscriptions')->insertGetId([
            'team_id' => $teamId, 'plan_id' => $planId, 'status' => $status, 'billing_cycle' => 'monthly',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_team_with_several_live_subscriptions_keeps_only_the_newest_and_the_rest_are_canceled_with_an_audit_row(): void
    {
        $migration = $this->migration('2026_10_19_100200_add_subscription_engine_columns.php');
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $plan = $this->planId();

        $migration->down();
        $oldest = $this->subscription($team->id, $plan, 'trialing');
        $middle = $this->subscription($team->id, $plan, 'active');
        $newest = $this->subscription($team->id, $plan, 'past_due');
        $alreadyCanceled = $this->subscription($team->id, $plan, 'canceled');
        $singleton = $this->subscription($otherTeam->id, $plan, 'active');

        $migration->up();

        $statuses = DB::table('billing_subscriptions')->pluck('status', 'id');
        $this->assertSame('canceled', $statuses[$oldest]);
        $this->assertSame('canceled', $statuses[$middle]);
        $this->assertSame('past_due', $statuses[$newest]);
        $this->assertSame('canceled', $statuses[$alreadyCanceled]);
        $this->assertSame('active', $statuses[$singleton], 'a team with one live subscription is not touched');

        $audits = DB::table('billing_audit_events')->where('action', 'subscription.deduplicated')->get();
        $this->assertEqualsCanonicalizing([$oldest, $middle], $audits->pluck('subject_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($newest, json_decode($audits->first()->meta, true)['kept_subscription_id']);
        $this->assertNotNull(DB::table('billing_subscriptions')->where('id', $oldest)->value('ended_at'));
        $this->assertSame(5, DB::table('billing_subscriptions')->count(), 'no row is ever deleted');
    }

    public function test_after_the_migration_the_database_refuses_a_second_live_subscription(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            $this->markTestSkipped('Partial unique index: sqlite and pgsql only.');
        }

        $migration = $this->migration('2026_10_19_100200_add_subscription_engine_columns.php');
        $team = Team::factory()->create();
        $plan = $this->planId();

        $migration->down();
        $this->subscription($team->id, $plan, 'active');
        $migration->up();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->subscription($team->id, $plan, 'trialing');
    }

    public function test_the_subscription_migration_rolls_back_cleanly(): void
    {
        $this->migration('2026_10_19_100200_add_subscription_engine_columns.php')->down();

        $this->assertFalse(Schema::hasColumn('billing_subscriptions', 'cancel_at_period_end'));
        $this->assertFalse(Schema::hasColumn('billing_plans', 'trial_days'));
    }

    public function test_the_invoice_migration_backfills_amount_paid_and_makes_numbers_unique_per_team(): void
    {
        $migration = $this->migration('2026_10_19_100300_add_invoice_engine_columns.php');
        $a = Team::factory()->create();
        $b = Team::factory()->create();

        $migration->down();

        $now = now()->toDateTimeString();
        $invoice = fn (int $team, string $number, string $status, int $total) => DB::table('billing_invoices')->insertGetId([
            'team_id' => $team, 'invoice_number' => $number, 'status' => $status, 'currency' => 'ZAR',
            'subtotal' => $total / 100, 'tax' => 0, 'total' => $total / 100,
            'subtotal_cents' => $total, 'tax_cents' => 0, 'total_cents' => $total,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $payment = fn (int $team, int $invoice, int $cents, string $status) => DB::table('billing_payments')->insert([
            'team_id' => $team, 'invoice_id' => $invoice, 'amount' => $cents / 100, 'amount_cents' => $cents,
            'currency' => 'ZAR', 'status' => $status, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $paid = $invoice($a->id, 'L-1', 'paid', 10000);
        $partly = $invoice($a->id, 'L-2', 'open', 20000);
        $overpaid = $invoice($a->id, 'L-3', 'open', 20000);
        $untouched = $invoice($a->id, 'L-4', 'open', 5000);
        $payment($a->id, $partly, 5000, 'succeeded');
        $payment($a->id, $partly, 3000, 'succeeded');
        $payment($a->id, $partly, 9999, 'failed');
        $payment($a->id, $overpaid, 30000, 'succeeded');

        $migration->up();

        $paidCents = fn (int $id) => (int) DB::table('billing_invoices')->where('id', $id)->value('amount_paid_cents');
        $this->assertSame(10000, $paidCents($paid), 'a paid invoice counts as fully paid');
        $this->assertSame(8000, $paidCents($partly), 'only succeeded payments count');
        $this->assertSame(20000, $paidCents($overpaid), 'capped at the invoice total');
        $this->assertSame(0, $paidCents($untouched));

        $invoice($b->id, 'L-1', 'open', 100);
        $this->assertSame(2, DB::table('billing_invoices')->where('invoice_number', 'L-1')->count(), 'another team may reuse a number');

        $this->expectException(UniqueConstraintViolationException::class);
        $invoice($a->id, 'L-1', 'open', 100);
    }

    public function test_the_invoice_migration_rolls_back_cleanly(): void
    {
        $this->migration('2026_10_19_100300_add_invoice_engine_columns.php')->down();

        $this->assertFalse(Schema::hasTable('billing_invoice_sequences'));
        $this->assertFalse(Schema::hasColumn('billing_invoices', 'subscription_id'));
    }
}
