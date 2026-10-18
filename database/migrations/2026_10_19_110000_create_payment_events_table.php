<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider-agnostic seam for money arriving: one row per payment fact, whatever its source
 * (a person recording an EFT today; a provider adapter's webhook later). Append-only.
 *
 * unique(provider, external_id) is what makes replays harmless. When either column is NULL the
 * unique index does not apply (NULLs are distinct on SQLite, PostgreSQL and MySQL), so manual
 * entries without a provider id never collide with each other. The payload is stored with secrets
 * scrubbed (RecordPayment does the scrubbing; this table never receives raw credentials).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('billing_payments')->restrictOnDelete();
            $table->string('provider', 40)->nullable();
            $table->string('external_id', 191)->nullable();
            $table->string('type', 32)->default('payment');
            $table->bigInteger('amount_cents');
            $table->string('currency', 3);
            $table->json('payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['team_id', 'received_at']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
