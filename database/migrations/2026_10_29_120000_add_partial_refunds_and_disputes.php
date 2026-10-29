<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seminar tickets: partial refunds and disputes (docs/seminar-payments.md). Additive.
 *
 * ticket_payments.status gains partially_refunded, disputed and chargeback (so it is widened), and a ticket
 * keeps a running refunded_cents. Each refund is a ticket_refunds row, unique per (ticket, refund_key), which
 * is how a refund request is idempotent and how Paystack's refund.processed is matched to it. Each Paystack
 * dispute on a ticket is a ticket_disputes row, unique on Paystack's dispute id: Billing only records disputes,
 * it never submits evidence or resolves one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('refunded_cents')->default(0);
        });

        Schema::table('ticket_payments', function (Blueprint $table) {
            $table->string('status', 24)->default('pending')->change();
        });

        Schema::create('ticket_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_payment_id')->constrained('ticket_payments')->restrictOnDelete();
            $table->string('refund_key', 64);
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 16)->default('pending');
            $table->string('paystack_refund_id', 191)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['ticket_payment_id', 'refund_key']);
            $table->index('paystack_refund_id');
        });

        Schema::create('ticket_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_payment_id')->constrained('ticket_payments')->restrictOnDelete();
            $table->string('paystack_dispute_id', 64)->unique();
            $table->string('status', 16)->default('open');
            $table->string('resolution', 16)->nullable();
            $table->string('paystack_resolution', 64)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('ticket_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_disputes');
        Schema::dropIfExists('ticket_refunds');

        Schema::table('ticket_payments', function (Blueprint $table) {
            $table->dropColumn('refunded_cents');
        });
    }
};
