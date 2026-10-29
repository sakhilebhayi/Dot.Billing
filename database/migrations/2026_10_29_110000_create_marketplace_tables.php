<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seminar ticket marketplace (docs/seminar-payments.md). Additive: two new tables, nothing existing changes.
 *
 * payout_accounts   one row per InfoDot host (host_ref). Only Paystack's subaccount code (encrypted by the
 *                   model) and the last four digits are kept. The bank account number itself is never stored.
 * ticket_payments   one row per sold ticket. ticket_ref is InfoDot's idempotency key; paystack_reference is
 *                   ours. Money is whole cents. platform_fee_cents is InfoDot's share, fixed when the ticket
 *                   is created and sent to Paystack as a flat charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('host_ref', 64)->unique();
            $table->text('paystack_subaccount_code');
            $table->string('bank_code', 32);
            $table->string('account_last4', 4);
            $table->string('business_name', 150);
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('ticket_payments', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_ref', 64)->unique();
            $table->foreignId('payout_account_id')->constrained('payout_accounts')->restrictOnDelete();
            $table->string('buyer_email', 254);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('platform_fee_cents');
            $table->string('currency', 3);
            $table->string('description', 140);
            $table->string('return_url', 300)->nullable();
            $table->string('paystack_reference', 100)->unique();
            $table->text('authorization_url')->nullable();
            $table->string('paystack_transaction_id', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('failure_reason', 60)->nullable();
            $table->timestamp('refund_requested_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('payout_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_payments');
        Schema::dropIfExists('payout_accounts');
    }
};
