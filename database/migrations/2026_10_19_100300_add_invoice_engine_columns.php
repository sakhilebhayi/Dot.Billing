<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the invoice engine needs on an invoice, and a way to number them.
 *
 * - subscription_id + period_start/period_end say which billing period an invoice covers; the
 *   unique (subscription_id, period_start) is what makes generation idempotent even under a race
 *   (legacy invoices have NULL there, and NULLs never collide).
 * - amount_paid_cents is the running total of payments applied; backfilled for legacy rows (paid
 *   invoices count as fully paid, others get the sum of their succeeded payments, capped at the total).
 * - invoice_number was globally unique; numbering is now per team, so uniqueness is (team_id, number).
 * - billing_invoice_sequences holds the per-team, per-year counter, locked while a number is allocated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            // See 2026_10_19_100200: on SQLite adding a foreign key rebuilds the table and fires the
            // ON DELETE rules of every table pointing at it, so SQLite gets a plain column.
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('subscription_id')->nullable();
            } else {
                $table->foreignId('subscription_id')->nullable()->constrained('billing_subscriptions')->restrictOnDelete();
            }
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->bigInteger('amount_paid_cents')->default(0);
            $table->unsignedInteger('tax_rate_bps')->default(0);
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('voided_at')->nullable();
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropUnique(['invoice_number']);
            $table->unique(['team_id', 'invoice_number']);
            $table->unique(['subscription_id', 'period_start']);
            $table->index('status');
            $table->index('due_date');
            $table->index(['team_id', 'status']);
        });

        Schema::create('billing_invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'year']);
        });

        $this->backfillAmountPaid();
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_sequences');

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropUnique(['subscription_id', 'period_start']);
            $table->dropUnique(['team_id', 'invoice_number']);
            $table->dropIndex(['status']);
            $table->dropIndex(['due_date']);
            $table->dropIndex(['team_id', 'status']);
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->unique('invoice_number');

            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['subscription_id']);
            }

            $table->dropColumn(['subscription_id', 'issued_at', 'period_start', 'period_end', 'amount_paid_cents', 'tax_rate_bps', 'grace_until', 'voided_at']);
        });
    }

    private function backfillAmountPaid(): void
    {
        DB::table('billing_invoices')->whereIn('status', ['paid', 'open', 'uncollectible'])->orderBy('id')->chunkById(500, function ($invoices) {
            foreach ($invoices as $invoice) {
                $total = (int) $invoice->total_cents;

                if ($invoice->status === 'paid') {
                    $paid = $total;
                } else {
                    $paid = min($total, (int) DB::table('billing_payments')
                        ->where('invoice_id', $invoice->id)
                        ->where('status', 'succeeded')
                        ->sum('amount_cents'));
                }

                if ($paid > 0) {
                    DB::table('billing_invoices')->where('id', $invoice->id)->update(['amount_paid_cents' => $paid]);
                }
            }
        });
    }
};
