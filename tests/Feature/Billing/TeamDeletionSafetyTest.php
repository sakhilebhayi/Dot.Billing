<?php

namespace Tests\Feature\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Laravel\Jetstream\Http\Livewire\DeleteTeamForm;
use Livewire\Livewire;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class TeamDeletionSafetyTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_19_120000_restrict_financial_foreign_keys.php');
    }

    private function skipUnlessEngineEnforcesRestrict(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('The foreign-key change is applied on pgsql and mysql only: SQLite rebuilds the table, which would fire the old ON DELETE rules on live rows.');
        }
    }

    private function ownedTeamWithInvoice(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);
        $invoice = $this->makeInvoice($team, ['invoice_number' => 'KEEP-ME-DISTINCT-6620']);

        return [$owner, $team, $invoice];
    }

    public function test_a_team_with_billing_history_cannot_be_deleted_from_the_app_and_nothing_is_lost(): void
    {
        [$owner, $team, $invoice] = $this->ownedTeamWithInvoice();
        $this->actingAs($owner);

        Livewire::test(DeleteTeamForm::class, ['team' => $team->fresh()])
            ->call('deleteTeam')
            ->assertHasErrors(['team']);

        $this->assertNotNull($team->fresh());
        $this->assertNotNull(BillingInvoice::withoutGlobalScope('team')->find($invoice->id));
    }

    public function test_a_team_with_only_a_subscription_is_also_protected(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);
        $sub = $this->makeSubscription($team, $this->makePlan());
        $this->actingAs($owner);

        Livewire::test(DeleteTeamForm::class, ['team' => $team->fresh()])->call('deleteTeam')->assertHasErrors(['team']);

        $this->assertNotNull(BillingSubscription::withoutGlobalScope('team')->find($sub->id));
    }

    public function test_a_team_with_no_billing_records_can_still_be_deleted(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);
        $this->actingAs($owner);

        Livewire::test(DeleteTeamForm::class, ['team' => $team->fresh()])->call('deleteTeam')->assertHasNoErrors();

        $this->assertNull($team->fresh());
    }

    public function test_deleting_an_account_that_owns_a_team_with_billing_history_is_refused_and_rolled_back(): void
    {
        [$owner, $team] = $this->ownedTeamWithInvoice();
        $personal = $owner->ownedTeams()->where('personal_team', true)->first();

        try {
            app(DeletesUsers::class)->delete($owner);
            $this->fail('expected the deletion to be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        $this->assertNotNull(User::find($owner->id), 'the account survives');
        $this->assertNotNull(Team::find($team->id));
        $this->assertNotNull(Team::find($personal->id), 'the earlier deletion of the personal team was rolled back too');
    }

    public function test_the_database_itself_refuses_to_delete_a_team_that_has_financial_records(): void
    {
        $this->skipUnlessEngineEnforcesRestrict();
        [, $team] = $this->ownedTeamWithInvoice();

        $this->expectException(QueryException::class);
        DB::table('teams')->where('id', $team->id)->delete();
    }

    public function test_the_restrict_migration_keeps_every_legacy_row_and_then_restricts(): void
    {
        $this->skipUnlessEngineEnforcesRestrict();
        $migration = $this->migration();
        $team = Team::factory()->create();

        $migration->down(); // back to cascade, the way production is today
        $now = now()->toDateTimeString();
        $invoiceId = DB::table('billing_invoices')->insertGetId(['team_id' => $team->id, 'invoice_number' => 'LEGACY-FK-1', 'status' => 'open', 'currency' => 'ZAR', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('billing_invoice_items')->insert(['invoice_id' => $invoiceId, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'amount' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('billing_payments')->insert(['team_id' => $team->id, 'invoice_id' => $invoiceId, 'amount' => 1, 'currency' => 'ZAR', 'status' => 'succeeded', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('dunning_cases')->insert(['team_id' => $team->id, 'invoice_id' => $invoiceId, 'reason' => 'invoice_overdue', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);

        $migration->up();

        $this->assertSame(1, DB::table('billing_invoices')->count());
        $this->assertSame(1, DB::table('billing_invoice_items')->count());
        $this->assertSame(1, DB::table('billing_payments')->where('invoice_id', $invoiceId)->count());
        $this->assertSame(1, DB::table('dunning_cases')->count());

        $this->expectException(QueryException::class);
        DB::table('teams')->where('id', $team->id)->delete();
    }

    public function test_every_financial_table_now_restricts_instead_of_cascading(): void
    {
        $this->skipUnlessEngineEnforcesRestrict();
        $rules = DB::select("select tc.table_name as t, kcu.column_name as c, rc.delete_rule as r
            from information_schema.table_constraints tc
            join information_schema.key_column_usage kcu on tc.constraint_name = kcu.constraint_name
            join information_schema.referential_constraints rc on rc.constraint_name = tc.constraint_name
            where tc.constraint_type = 'FOREIGN KEY'");
        $byColumn = collect($rules)->mapWithKeys(fn ($r) => [$r->t.'.'.$r->c => strtoupper($r->r)]);

        foreach (['billing_subscriptions.team_id', 'billing_invoices.team_id', 'billing_invoice_items.invoice_id', 'billing_payments.team_id', 'billing_payments.invoice_id', 'dunning_cases.team_id', 'dunning_cases.invoice_id', 'dunning_cases.payment_id', 'billing_audit_events.team_id', 'payment_events.team_id'] as $column) {
            $this->assertSame('RESTRICT', $byColumn[$column] ?? null, $column);
        }
    }

    public function test_the_indexes_the_engine_and_scanner_rely_on_exist(): void
    {
        $expected = [
            'billing_invoices' => [['status'], ['due_date'], ['team_id', 'status'], ['subscription_id', 'period_start']],
            'billing_payments' => [['status'], ['invoice_id']],
            'billing_alerts' => [['team_id', 'type', 'status']],
            'billing_subscriptions' => [['team_id', 'status'], ['status', 'current_period_end']],
            'dunning_cases' => [['status'], ['team_id', 'status'], ['invoice_id']],
        ];

        foreach ($expected as $table => $indexes) {
            foreach ($indexes as $columns) {
                $this->assertTrue(Schema::hasIndex($table, $columns), "{$table} (".implode(', ', $columns).')');
            }
        }
    }
}
