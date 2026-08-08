<?php

namespace App\Services;

use App\Models\BillingInvoice;

class PaymentReliabilityCalculator
{
    private const TRAILING_MONTHS = 6;

    public function calculate(): array
    {
        $windowStart = now()->subMonths(self::TRAILING_MONTHS);

        $eligible = BillingInvoice::where('due_date', '>=', $windowStart)
            ->where(function ($query) {
                $query->where('status', 'paid')
                    ->orWhere('status', 'uncollectible')
                    ->orWhere(function ($q) {
                        $q->where('status', 'open')->where('due_date', '<', now());
                    });
            })
            ->get();

        if ($eligible->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'insufficient_data',
                'eligible_invoice_count' => null,
                'on_time_count' => null,
                'on_time_rate_pct' => null,
                'basis' => null,
                'what_if' => null,
            ];
        }

        $onTimeCount = $eligible->filter(function (BillingInvoice $invoice) {
            return $invoice->status === 'paid'
                && $invoice->paid_at !== null
                && $invoice->paid_at->lte($invoice->due_date);
        })->count();

        $eligibleCount = $eligible->count();
        $onTimeRatePct = (int) round(($onTimeCount / $eligibleCount) * 100);

        $basis = sprintf(
            'On-time payment rate across %d invoice(s) that came due in the trailing %d months, as of %s.',
            $eligibleCount,
            self::TRAILING_MONTHS,
            now()->toDateString()
        );

        $overdue = BillingInvoice::where('status', 'open')
            ->where('due_date', '<', now())
            ->count();

        $whatIf = null;
        if ($overdue > 0) {
            // Overdue invoices are already counted in $eligibleCount (they
            // match the "open AND due_date < now()" eligibility branch
            // above), so only the numerator changes if they were resolved
            // -- resolving them doesn't add new eligible invoices, it
            // reclassifies existing ones.
            $projectedRate = (int) round((($onTimeCount + $overdue) / $eligibleCount) * 100);
            $whatIf = [
                'overdue_count' => $overdue,
                'projected_resolved_rate_pct' => min(100, $projectedRate),
            ];
        }

        return [
            'available' => true,
            'reason' => null,
            'eligible_invoice_count' => $eligibleCount,
            'on_time_count' => $onTimeCount,
            'on_time_rate_pct' => $onTimeRatePct,
            'basis' => $basis,
            'what_if' => $whatIf,
        ];
    }
}
