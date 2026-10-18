<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries the scanner, the clock and the dashboard already run. Invoice and
 * subscription indexes were added with the engine columns; these are the rest. Each is skipped if it
 * already exists (MySQL creates one implicitly for a foreign key).
 */
return new class extends Migration
{
    private const INDEXES = [
        'billing_payments' => [['status'], ['invoice_id']],
        'billing_alerts' => [['team_id', 'type', 'status']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (! Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($columns));
                }
            }
        }
    }
};
