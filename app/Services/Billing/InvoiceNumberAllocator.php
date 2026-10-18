<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hands out the next invoice number for a team.
 *
 * The counter row for (team, year) is locked while the number is chosen, so two processes cannot
 * take the same one. The allocation joins the caller's transaction: if the invoice insert fails and
 * rolls back, so does the counter, which is what keeps numbering gap-free in practice (a number is
 * only ever burned by something committed). A number already in use by an older invoice is skipped.
 */
class InvoiceNumberAllocator
{
    public function next(int $teamId, ?CarbonInterface $at = null): string
    {
        $at ??= now();

        return DB::transaction(function () use ($teamId, $at) {
            $year = $at->year;
            $now = now();

            DB::table('billing_invoice_sequences')->insertOrIgnore([
                'team_id' => $teamId, 'year' => $year, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $row = DB::table('billing_invoice_sequences')
                ->where('team_id', $teamId)->where('year', $year)
                ->lockForUpdate()->first();

            $number = (int) $row->last_number;

            for ($attempts = 0; $attempts < 1000; $attempts++) {
                $number++;
                $candidate = $this->format($number, $at);

                $taken = BillingInvoice::withoutGlobalScope('team')
                    ->where('team_id', $teamId)->where('invoice_number', $candidate)->exists();

                if (! $taken) {
                    DB::table('billing_invoice_sequences')
                        ->where('id', $row->id)
                        ->update(['last_number' => $number, 'updated_at' => $now]);

                    return $candidate;
                }
            }

            throw new RuntimeException("Could not find a free invoice number for team {$teamId}.");
        });
    }

    private function format(int $number, CarbonInterface $at): string
    {
        $format = (string) config('billing.invoice.number_format', 'INV-{YYYY}-{seq}');

        if (! str_contains($format, '{seq}')) {
            $format .= '-{seq}';
        }

        $pad = max(1, (int) config('billing.invoice.number_pad', 4));

        return strtr($format, [
            '{YYYY}' => $at->format('Y'),
            '{YY}' => $at->format('y'),
            '{MM}' => $at->format('m'),
            '{seq}' => str_pad((string) $number, $pad, '0', STR_PAD_LEFT),
        ]);
    }
}
