<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment event can now be something an operator must look at, not only a payment that was applied.
 *
 *  status  applied      a payment RecordPayment applied (every existing row; the default)
 *          unmatched    a signed provider event we could not tie to an invoice, or whose facts disagree
 *                       with what we asked for (amount, currency, reference): nothing was applied
 *          needs_review money arrived that the rules refuse to apply (overpayment, invoice already
 *                       settled) or an event type the ledger does not model (refunds): nothing was applied
 *  reason  a stable code saying why (amount_mismatch, overpayment, refund_not_modelled, ...)
 *
 * team_id becomes nullable because an unmatched event may belong to no company we know. Rows for
 * unmatched / needs_review events carry an external_id prefixed "held:" so they de-duplicate on
 * unique(provider, external_id) without ever blocking the real payment from being applied later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->string('status', 24)->default('applied');
            $table->string('reason', 60)->nullable();
            $table->index('status');
        });

        Schema::table('payment_events', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'reason']);
        });
    }
};
