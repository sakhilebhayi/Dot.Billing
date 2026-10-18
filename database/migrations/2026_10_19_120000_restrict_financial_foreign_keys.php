<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Financial records must not vanish because a team was deleted.
 *
 * The first billing migration made every team_id foreign key ON DELETE CASCADE (and payments.invoice_id
 * SET NULL), so deleting a team silently destroyed its subscriptions, invoices, payments and dunning
 * history. These become RESTRICT: the database refuses the delete while financial rows exist, and the app
 * (DeleteTeam / DeleteUser) refuses first with a readable message. Chosen over soft-deleting teams because
 * it changes no existing row and no query; the cost is that a team with billing history cannot be erased.
 * Operational tables (usage records, alerts) still cascade; they are not financial records.
 *
 * pgsql and mysql only. On SQLite, changing a foreign key rebuilds the table, which fires the OLD ON DELETE
 * rules on live rows (nulling payment links, cascading items); SQLite is a development and test engine
 * here, so it keeps the old constraints and relies on the app-level guard.
 */
return new class extends Migration
{
    /** table => [[column, referenced table, original on-delete rule]] */
    private const KEYS = [
        'billing_subscriptions' => [['team_id', 'teams', 'cascade']],
        'billing_invoices' => [['team_id', 'teams', 'cascade']],
        'billing_invoice_items' => [['invoice_id', 'billing_invoices', 'cascade']],
        'billing_payments' => [['team_id', 'teams', 'cascade'], ['invoice_id', 'billing_invoices', 'set null']],
        'billing_credits' => [['team_id', 'teams', 'cascade']],
        'dunning_cases' => [['team_id', 'teams', 'cascade'], ['invoice_id', 'billing_invoices', 'cascade'], ['payment_id', 'billing_payments', 'cascade']],
    ];

    public function up(): void
    {
        $this->rewrite(fn (Blueprint $table, string $column, string $references, string $original) => $table->foreign($column)->references('id')->on($references)->restrictOnDelete());
    }

    public function down(): void
    {
        $this->rewrite(function (Blueprint $table, string $column, string $references, string $original) {
            $foreign = $table->foreign($column)->references('id')->on($references);
            $original === 'set null' ? $foreign->nullOnDelete() : $foreign->cascadeOnDelete();
        });
    }

    private function rewrite(callable $define): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::KEYS as $table => $keys) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($keys) {
                foreach ($keys as [$column, $references, $original]) {
                    $blueprint->dropForeign([$column]);
                }
            });

            Schema::table($table, function (Blueprint $blueprint) use ($keys, $define) {
                foreach ($keys as [$column, $references, $original]) {
                    $define($blueprint, $column, $references, $original);
                }
            });
        }
    }
};
