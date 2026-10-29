<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Raises the invoice for a subscription's current billing period.
 *
 * Billing is in advance: one invoice per period, issued when the period starts. Idempotent: a period
 * that already has an invoice returns null, and the database's unique (subscription_id, period_start)
 * backs that up if two processes race. Nothing is ever charged here; an invoice is only a demand.
 *
 * Not invoiced: free plans (price 0, unless they hold paid extra seats), trials, and subscriptions that are not active or past due.
 * Line items are snapshots (description, quantity, unit price, amount): later plan edits never touch them.
 */
class InvoiceGenerator
{
    public function __construct(private InvoiceNumberAllocator $numbers, private AuditLog $audit) {}

    public function generateForCurrentPeriod(BillingSubscription $subscription, ?CarbonInterface $now = null): ?BillingInvoice
    {
        $now ??= now();

        if (! in_array($subscription->status, ['active', 'past_due'], true)
            || $subscription->current_period_start === null
            || $subscription->current_period_end === null
            || $subscription->current_period_start->gt($now)) {
            return null;
        }

        $plan = $subscription->plan;
        $price = $plan->priceFor($subscription->billing_cycle);
        $extraUnit = $this->extraSeatUnitPrice($subscription);

        // Free plans are not invoiced, unless the team holds paid extra seats on them.
        if (! $price->isPositive() && $extraUnit === null) {
            return null;
        }

        $alreadyInvoiced = BillingInvoice::withoutGlobalScope('team')
            ->where('subscription_id', $subscription->id)
            ->where('period_start', $subscription->current_period_start)
            ->exists();

        if ($alreadyInvoiced) {
            return null;
        }

        try {
            return DB::transaction(fn () => $this->create($subscription, $plan->name, $price, $extraUnit, $now));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * The per-seat price to bill, or null when there are no settled extra seats or the plan has no price
     * for this cycle (nothing is ever billed for seats without a configured price).
     */
    private function extraSeatUnitPrice(BillingSubscription $subscription): ?Money
    {
        if ((int) $subscription->extra_seats <= 0) {
            return null;
        }

        $unit = $subscription->plan->extraSeatPriceFor($subscription->billing_cycle);

        return $unit?->isPositive() ? $unit : null;
    }

    private function create(BillingSubscription $subscription, string $planName, Money $price, ?Money $extraUnit, CarbonInterface $now): BillingInvoice
    {
        $seats = (int) $subscription->extra_seats;
        $extra = $extraUnit?->times($seats);
        $subtotal = $extra === null ? $price : $price->add($extra);
        $bps = Money::decimalToCents((string) config('billing.invoice.tax_rate_percent', '0'));
        $tax = $subtotal->percentage($bps);
        $finalize = (bool) config('billing.invoice.auto_finalize', true);
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        $invoice = BillingInvoice::create([
            'team_id' => $subscription->team_id,
            'subscription_id' => $subscription->id,
            'invoice_number' => $this->numbers->next($subscription->team_id, $now),
            'status' => $finalize ? 'open' : 'draft',
            'currency' => $price->currency,
            'subtotal_cents' => $subtotal->cents,
            'tax_cents' => $tax->cents,
            'total_cents' => $subtotal->add($tax)->cents,
            'tax_rate_bps' => $bps,
            'period_start' => $start,
            'period_end' => $end,
            'issued_at' => $finalize ? $now : null,
            'due_date' => $finalize ? $now->copy()->addDays((int) config('billing.invoice.payment_terms_days', 14)) : null,
        ]);

        $period = $start->format('j M Y').' to '.$end->format('j M Y');

        if ($price->isPositive()) {
            $invoice->items()->create([
                'description' => "{$planName} plan, {$subscription->billing_cycle} subscription",
                'quantity' => 1,
                'unit_price_cents' => $price->cents,
                'amount_cents' => $price->cents,
                'period' => $period,
            ]);
        }

        if ($extraUnit !== null) {
            $invoice->items()->create([
                'description' => "Extra seats x {$seats}",
                'quantity' => $seats,
                'unit_price_cents' => $extraUnit->cents,
                'amount_cents' => $extra->cents,
                'period' => $period,
            ]);
        }

        $this->audit->record('invoice.generated', $subscription->team_id, null, $invoice, [
            'invoice_number' => $invoice->invoice_number,
            'total_cents' => $invoice->total_cents,
            'currency' => $invoice->currency,
            'status' => $invoice->status,
        ]);

        return $invoice;
    }
}
