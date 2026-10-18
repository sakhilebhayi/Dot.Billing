<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a team here to the company (team) in InfoDot, the ecosystem's identity
 * authority. InfoDot and this app have separate databases and unrelated team ids,
 * so the InfoDot team uuid is the only shared name for a company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teams') && ! Schema::hasColumn('teams', 'infodot_team_uuid')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->string('infodot_team_uuid', 36)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'infodot_team_uuid')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropUnique(['infodot_team_uuid']);
                $table->dropColumn('infodot_team_uuid');
            });
        }
    }
};
