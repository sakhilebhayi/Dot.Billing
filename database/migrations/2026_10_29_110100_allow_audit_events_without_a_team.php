<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace events (a host's payout account, a seminar ticket) belong to no Dot.Billing team, so the
 * audit trail's team_id becomes nullable, the same change payment_events got for unmatched events.
 * Every existing row keeps its team. Teams are still protected from deletion by the foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_audit_events', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left nullable on purpose: rows without a team may exist by then, and putting NOT NULL back would fail.
    }
};
