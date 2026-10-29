<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recovering a lost chargeback from the host (docs/seminar-payments.md, "Chargeback recovery"). Additive and
 * guarded, so running it twice changes nothing.
 *
 * host_agreements        the term a host accepted, one row per (payout account, version). Recovery is taken only
 *                        for a host that has accepted the CURRENT version.
 * host_debts             one row per lost dispute (ticket_payment_id is unique), in whole cents.
 * host_debt_recoveries   the ledger: how much of which ticket's larger fee was credited to which debt, and how much of
 *                        it a later refund gave back. It is what makes crediting idempotent and reversible.
 * ticket_payments        recovery_cents (the extra fee asked of Paystack on that ticket), recovery_applied_cents
 *                        (credited to debts when the ticket was verified paid) and recovery_reversed_cents (given back).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('host_agreements')) {
            Schema::create('host_agreements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payout_account_id')->constrained('payout_accounts')->restrictOnDelete();
                $table->string('version', 32);
                $table->timestamp('accepted_at');
                $table->string('accepted_by_ref', 64);
                $table->timestamps();

                $table->unique(['payout_account_id', 'version']);
            });
        }

        if (! Schema::hasTable('host_debts')) {
            Schema::create('host_debts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payout_account_id')->constrained('payout_accounts')->restrictOnDelete();
                $table->foreignId('ticket_payment_id')->unique()->constrained('ticket_payments')->restrictOnDelete();
                $table->string('currency', 3);
                $table->unsignedBigInteger('amount_cents');
                $table->unsignedBigInteger('recovered_cents')->default(0);
                $table->string('status', 16)->default('open');
                $table->timestamp('cleared_at')->nullable();
                $table->timestamp('written_off_at')->nullable();
                $table->string('written_off_by_ref', 64)->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();

                $table->index(['payout_account_id', 'status']);
            });
        }

        if (! Schema::hasTable('host_debt_recoveries')) {
            Schema::create('host_debt_recoveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('host_debt_id')->constrained('host_debts')->restrictOnDelete();
                $table->foreignId('ticket_payment_id')->constrained('ticket_payments')->restrictOnDelete();
                $table->unsignedBigInteger('amount_cents');
                $table->unsignedBigInteger('reversed_cents')->default(0);
                $table->timestamps();

                $table->unique(['host_debt_id', 'ticket_payment_id']);
                $table->index('ticket_payment_id');
            });
        }

        Schema::table('ticket_payments', function (Blueprint $table) {
            foreach (['recovery_cents', 'recovery_applied_cents', 'recovery_reversed_cents'] as $column) {
                if (! Schema::hasColumn('ticket_payments', $column)) {
                    $table->unsignedBigInteger($column)->default(0);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_debt_recoveries');
        Schema::dropIfExists('host_debts');
        Schema::dropIfExists('host_agreements');

        Schema::table('ticket_payments', function (Blueprint $table) {
            $table->dropColumn(['recovery_cents', 'recovery_applied_cents', 'recovery_reversed_cents']);
        });
    }
};
