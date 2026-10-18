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
 * Not invoiced: free plans (price 0), trials, and subscriptions that are not active or past due.
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

        if (! $price->isPositive()) {
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
            return DB::transaction(fn () => $this->create($subscription, $plan->name, $price, $now));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function create(BillingSubscription $subscription, string $planName, Money $price, CarbonInterface $now): BillingInvoice
    {
        $bps = Money::decimalToCents((string) config('billing.invoice.tax_rate_percent', '0'));
        $tax = $price->percentage($bps);
        $finalize = (bool) config('billing.invoice.auto_finalize', true);
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        $invoice = BillingInvoice::create([
            'team_id' => $subscription->team_id,
            'subscription_id' => $subscription->id,
            'invoice_number' => $this->numbers->next($subscription->team_id, $now),
            'status' => $finalize ? 'open' : 'draft',
            'currency' => $price->currency,
            'subtotal_cents' => $price->cents,
            'tax_cents' => $tax->cents,
            'total_cents' => $price->add($tax)->cents,
            'tax_rate_bps' => $bps,
            'period_start' => $start,
            'period_end' => $end,
            'issued_at' => $finalize ? $now : null,
            'due_date' => $finalize ? $now->copy()->addDays((int) config('billing.invoice.payment_terms_days', 14)) : null,
        ]);

        $invoice->items()->create([
            'description' => "{$planName} plan, {$subscription->billing_cycle} subscription",
            'quantity' => 1,
            'unit_price_cents' => $price->cents,
            'amount_cents' => $price->cents,
            'period' => $start->format('j M Y').' to '.$end->format('j M Y'),
        ]);

        $this->audit->record('invoice.generated', $subscription->team_id, null, $invoice, [
            'invoice_number' => $invoice->invoice_number,
            'total_cents' => $invoice->total_cents,
            'currency' => $invoice->currency,
            'status' => $invoice->status,
        ]);

        return $invoice;
    }
}
