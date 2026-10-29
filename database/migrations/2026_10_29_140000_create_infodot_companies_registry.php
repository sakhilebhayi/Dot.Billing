<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company registry (docs/company-registry.md). Purely additive and guarded, so running it twice changes nothing.
 *
 * - infodot_companies: the companies InfoDot has told Billing about, whether or not anyone has signed in to
 *   Billing yet. It creates no team and no user. deleted_at is when InfoDot said the company was deleted.
 * - teams.infodot_deleted_at: set on an existing team when InfoDot says its company was deleted. It is a flag
 *   for operators only; nothing about the team's subscriptions, invoices or payments changes because of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('infodot_companies')) {
            Schema::create('infodot_companies', function (Blueprint $table) {
                $table->id();
                $table->string('company_uuid', 36)->unique();
                $table->string('name', 120);
                $table->timestamp('registered_at');
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('teams') && ! Schema::hasColumn('teams', 'infodot_deleted_at')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->timestamp('infodot_deleted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'infodot_deleted_at')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropColumn('infodot_deleted_at');
            });
        }

        Schema::dropIfExists('infodot_companies');
    }
};
