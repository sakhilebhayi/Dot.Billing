<?php

use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money becomes integer minor units.
 *
 * The decimal(10,2) columns were read back as floats and summed as floats. This adds a
 * *_cents bigint beside each money column and backfills it from the existing decimal by
 * string arithmetic (no float involved). The decimal columns are kept as a mirror that the
 * models rewrite from the cents (see SyncsLegacyDecimals), so a rollback or an old reader
 * still sees correct numbers. Nothing here deletes or rewrites a decimal.
 */
return new class extends Migration
{
    /** table => [decimal column => cents column] */
    private const COLUMNS = [
        'billing_plans' => ['price_monthly' => 'price_monthly_cents', 'price_annual' => 'price_annual_cents'],
        'billing_invoices' => ['subtotal' => 'subtotal_cents', 'tax' => 'tax_cents', 'total' => 'total_cents'],
        'billing_invoice_items' => ['unit_price' => 'unit_price_cents', 'amount' => 'amount_cents'],
        'billing_payments' => ['amount' => 'amount_cents'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $pairs) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $pairs) {
                foreach ($pairs as $cents) {
                    if (! Schema::hasColumn($table, $cents)) {
                        $blueprint->bigInteger($cents)->default(0);
                    }
                }
            });

            $this->backfill($table, $pairs);
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $pairs) {
            $existing = array_values(array_filter($pairs, fn (string $cents) => Schema::hasColumn($table, $cents)));

            if ($existing !== []) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($existing));
            }
        }
    }

    /**
     * @param  array<string, string>  $pairs
     */
    private function backfill(string $table, array $pairs): void
    {
        DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table, $pairs) {
            foreach ($rows as $row) {
                $update = [];

                foreach ($pairs as $decimal => $cents) {
                    $update[$cents] = Money::decimalToCents($row->{$decimal} === null ? null : (string) $row->{$decimal});
                }

                DB::table($table)->where('id', $row->id)->update($update);
            }
        });
    }
};
