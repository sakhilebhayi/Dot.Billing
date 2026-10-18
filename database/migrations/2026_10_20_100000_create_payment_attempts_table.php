<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per time a person started an online payment for an invoice. It is the link between the
 * provider's reference and our invoice: a webhook or a redirect back is matched to an attempt, never
 * to an invoice by a value the provider (or the browser) sends us.
 *
 * amount_cents is the invoice balance at the moment the attempt was made; a payment is accepted only
 * if the provider confirms exactly that amount in exactly that currency.
 *
 * "One live attempt per invoice" is a partial unique index (sqlite and PostgreSQL). MySQL and MariaDB
 * have no partial indexes, so there PaystackCheckout's check under the invoice row lock is the only guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('reference', 120)->unique();
            $table->bigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 24)->default('initiated');
            $table->text('authorization_url')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason', 120)->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index(['team_id', 'created_at']);
        });

        if (in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement("CREATE UNIQUE INDEX payment_attempts_one_live_per_invoice ON payment_attempts (invoice_id) WHERE status = 'initiated'");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_attempts_one_live_per_invoice');
        Schema::dropIfExists('payment_attempts');
    }
};
