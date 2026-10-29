<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra-seat purchasing (R-13). Purely additive and guarded, so running it twice changes nothing.
 *
 * - billing_plans: what one extra seat costs per month and per year, in cents. NULLABLE and with no
 *   default on purpose: null means "this plan sells no extra seats", and nothing is ever charged for
 *   seats unless an operator has set a price.
 * - billing_subscriptions: extra_seats is the number of extra seats that are PAID FOR and therefore
 *   count toward the entitlement; pending_extra_seats is a reduction scheduled for the next renewal.
 * - billing_seat_changes: one row per seat-change request. It is what makes a request idempotent per
 *   key and what links an add to its pro-rata invoice, so seats are applied only when that invoice settles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_plans', 'extra_seat_price_monthly_cents')) {
                $table->unsignedInteger('extra_seat_price_monthly_cents')->nullable();
            }
            if (! Schema::hasColumn('billing_plans', 'extra_seat_price_annual_cents')) {
                $table->unsignedInteger('extra_seat_price_annual_cents')->nullable();
            }
        });

        Schema::table('billing_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_subscriptions', 'extra_seats')) {
                $table->unsignedSmallInteger('extra_seats')->default(0);
            }
            if (! Schema::hasColumn('billing_subscriptions', 'pending_extra_seats')) {
                $table->unsignedSmallInteger('pending_extra_seats')->nullable();
            }
        });

        if (! Schema::hasTable('billing_seat_changes')) {
            Schema::create('billing_seat_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
                $table->foreignId('subscription_id')->constrained('billing_subscriptions')->restrictOnDelete();
                $table->string('request_key', 100);
                $table->string('kind', 12);
                $table->unsignedSmallInteger('from_extra_seats');
                $table->unsignedSmallInteger('to_extra_seats');
                $table->string('status', 20);
                $table->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->restrictOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->unique(['subscription_id', 'request_key']);
                $table->index(['subscription_id', 'status']);
                $table->index('invoice_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_seat_changes');

        Schema::table('billing_subscriptions', function (Blueprint $table) {
            foreach (['extra_seats', 'pending_extra_seats'] as $column) {
                if (Schema::hasColumn('billing_subscriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('billing_plans', function (Blueprint $table) {
            foreach (['extra_seat_price_monthly_cents', 'extra_seat_price_annual_cents'] as $column) {
                if (Schema::hasColumn('billing_plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
