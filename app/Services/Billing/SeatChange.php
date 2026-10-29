<?php

namespace App\Services\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSeatChange;
use App\Models\BillingSubscription;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Buying and reducing extra seats on a live subscription (policy: config billing.seats; docs/seat-purchasing.md).
 *
 * The caller names the TARGET number of extra seats, not a delta, plus a request key.
 *
 *  - Adding: raises an invoice for the rest of the current period (daily pro rata by default). The seats
 *    do NOT count toward the entitlement yet; subscription.extra_seats only moves when that invoice is
 *    settled, through RecordPayment, the same path every payment takes (see settleInvoice()).
 *  - Reducing: scheduled for the period end (pending_extra_seats), no refund and no credit, applied by
 *    SubscriptionLifecycle at renewal (see onRenewal()).
 *  - Refused: adding on a plan with no extra-seat price (nothing is charged without one), a subscription
 *    that is not live, a negative count, a count above billing.seats.max_extra.
 *  - Idempotent per (subscription, request key): the same key again returns the first result and changes
 *    nothing. The subscription row is locked for the whole request.
 *
 * Authorization is the caller's job, as in SubscriptionService.
 */
class SeatChange
{
    public function __construct(
        private InvoiceNumberAllocator $numbers,
        private AuditLog $audit,
    ) {}

    public function change(BillingSubscription $subscription, int $targetExtraSeats, string $requestKey, ?User $actor = null, ?CarbonInterface $now = null): BillingSeatChange
    {
        $now ??= now();
        $requestKey = trim($requestKey);

        if ($requestKey === '' || strlen($requestKey) > 100) {
            throw BillingRuleViolation::because('invalid_request_key', 'A request key of 1 to 100 characters is required.');
        }

        try {
            return DB::transaction(fn () => $this->perform($subscription, $targetExtraSeats, $requestKey, $actor, $now));
        } catch (UniqueConstraintViolationException $e) {
            // Two requests with one key raced; the loser comes back here and becomes a replay.
            $existing = BillingSeatChange::withoutGlobalScope('team')
                ->where('subscription_id', $subscription->id)->where('request_key', $requestKey)->first();

            if ($existing === null) {
                throw $e;
            }

            return $this->replay($existing, $targetExtraSeats);
        }
    }

    private function perform(BillingSubscription $subscription, int $target, string $key, ?User $actor, CarbonInterface $now): BillingSeatChange
    {
        $sub = BillingSubscription::withoutGlobalScope('team')->with('plan')->lockForUpdate()->findOrFail($subscription->id);

        $existing = BillingSeatChange::withoutGlobalScope('team')
            ->where('subscription_id', $sub->id)->where('request_key', $key)->first();

        if ($existing !== null) {
            return $this->replay($existing, $target);
        }

        if (! $sub->isLive()) {
            throw BillingRuleViolation::because('invalid_state', 'This subscription has ended, so its seats cannot be changed.');
        }

        $max = (int) config('billing.seats.max_extra', 500);

        if ($target < 0) {
            throw BillingRuleViolation::because('invalid_seat_count', 'The number of extra seats cannot be negative.');
        }

        if ($target > $max) {
            throw BillingRuleViolation::because('seat_limit_exceeded', "A subscription can hold at most {$max} extra seats.");
        }

        $current = (int) $sub->extra_seats;
        $plan = $sub->plan;

        if ($target > $current) {
            if (! $plan->sellsExtraSeats($sub->billing_cycle)) {
                throw BillingRuleViolation::because('extra_seats_not_sold', 'This plan does not sell extra seats.');
            }

            if ($sub->status === 'trialing') {
                throw BillingRuleViolation::because('trial_no_extra_seats', 'Extra seats can be bought once the trial has ended.');
            }

            $waiting = BillingSeatChange::withoutGlobalScope('team')
                ->where('subscription_id', $sub->id)->where('status', BillingSeatChange::AWAITING_PAYMENT)->exists();

            if ($waiting) {
                throw BillingRuleViolation::because('seat_change_pending', 'An earlier seat purchase is still waiting for payment.');
            }

            return $this->add($sub, $plan, $current, $target, $key, $actor, $now);
        }

        return $target < $current
            ? $this->reduce($sub, $current, $target, $key, $actor)
            : $this->keep($sub, $current, $key, $actor);
    }

    private function add(BillingSubscription $sub, BillingPlan $plan, int $current, int $target, string $key, ?User $actor, CarbonInterface $now): BillingSeatChange
    {
        $unit = $plan->extraSeatPriceFor($sub->billing_cycle);
        $quantity = $target - $current;
        $start = $sub->current_period_start;
        $end = $sub->current_period_end;

        if ($start === null || $end === null) {
            throw BillingRuleViolation::because('invalid_state', 'This subscription has no current billing period.');
        }

        $mode = (string) config('billing.seats.add_charge', 'prorata');
        $full = $unit->times($quantity);
        $daysLeft = 0;
        $daysTotal = 0;

        if ($mode === 'prorata') {
            $daysTotal = (int) round($start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay(), true));
            $daysLeft = (int) round($now->copy()->startOfDay()->diffInDays($end->copy()->startOfDay(), false));
            $daysLeft = min($daysLeft, $daysTotal);

            if ($daysTotal <= 0 || $daysLeft <= 0) {
                throw BillingRuleViolation::because('period_ended', 'This billing period is ending. Buy the seats after it renews.');
            }

            $amount = $full->proRata($daysLeft, $daysTotal);
        } elseif ($mode === 'full_period') {
            $amount = $full;
        } else {
            throw new LogicException("billing.seats.add_charge must be 'prorata' or 'full_period', got '{$mode}'.");
        }

        if (! $amount->isPositive()) {
            throw BillingRuleViolation::because('nothing_to_charge', 'That purchase works out to nothing to charge.');
        }

        $this->supersedeScheduled($sub);
        $sub->forceFill(['pending_extra_seats' => null])->save();

        $invoice = $this->invoice($sub, $amount, $unit, $quantity, $mode === 'prorata' ? [$daysLeft, $daysTotal] : null, $now, $end);

        $change = $this->store($sub, $key, 'add', $current, $target, BillingSeatChange::AWAITING_PAYMENT, $invoice->id, $actor);

        $this->audit->record('seats.add_invoiced', $sub->team_id, $actor, $sub, [
            'seat_change_id' => $change->id, 'invoice_number' => $invoice->invoice_number,
            'from' => $current, 'to' => $target, 'total_cents' => $invoice->total_cents, 'currency' => $invoice->currency,
            'charge' => $mode,
        ]);

        return $change;
    }

    private function reduce(BillingSubscription $sub, int $current, int $target, string $key, ?User $actor): BillingSeatChange
    {
        $this->supersedeScheduled($sub);
        $sub->forceFill(['pending_extra_seats' => $target])->save();

        $change = $this->store($sub, $key, 'reduce', $current, $target, BillingSeatChange::SCHEDULED, null, $actor);

        $this->audit->record('seats.reduction_scheduled', $sub->team_id, $actor, $sub, [
            'seat_change_id' => $change->id, 'from' => $current, 'to' => $target,
            'effective' => $sub->current_period_end?->toIso8601String(),
        ]);

        return $change;
    }

    /** Asking for the seats already held cancels any reduction that was scheduled. */
    private function keep(BillingSubscription $sub, int $current, string $key, ?User $actor): BillingSeatChange
    {
        if ($sub->pending_extra_seats !== null) {
            $this->supersedeScheduled($sub);
            $sub->forceFill(['pending_extra_seats' => null])->save();
            $this->audit->record('seats.reduction_cancelled', $sub->team_id, $actor, $sub, ['extra_seats' => $current]);
        }

        return $this->store($sub, $key, 'keep', $current, $current, BillingSeatChange::APPLIED, null, $actor, applied: true);
    }

    /**
     * Called by RecordPayment when an invoice is paid in full, inside its transaction. If that invoice
     * is a seat purchase still awaiting payment, the seats start counting now, and not before.
     */
    public function settleInvoice(BillingInvoice $invoice): void
    {
        $change = BillingSeatChange::withoutGlobalScope('team')
            ->where('invoice_id', $invoice->id)->where('status', BillingSeatChange::AWAITING_PAYMENT)
            ->lockForUpdate()->first();

        if ($change === null) {
            return;
        }

        $sub = BillingSubscription::withoutGlobalScope('team')->lockForUpdate()->findOrFail($change->subscription_id);

        if (! $sub->isLive()) {
            $change->forceFill(['status' => BillingSeatChange::LAPSED])->save();
            $this->audit->record('seats.settled_not_applied', $sub->team_id, null, $sub, [
                'seat_change_id' => $change->id, 'invoice_number' => $invoice->invoice_number, 'reason' => 'subscription_ended',
            ]);

            return;
        }

        $sub->forceFill(['extra_seats' => $change->to_extra_seats])->save();
        $change->forceFill(['status' => BillingSeatChange::APPLIED, 'applied_at' => now()])->save();

        $this->audit->record('seats.applied', $sub->team_id, null, $sub, [
            'seat_change_id' => $change->id, 'invoice_number' => $invoice->invoice_number,
            'from' => $change->from_extra_seats, 'to' => $change->to_extra_seats,
        ]);
    }

    /**
     * Called by InvoiceService when an invoice is voided: a seat purchase whose invoice is voided never happens.
     */
    public function invoiceVoided(BillingInvoice $invoice): void
    {
        BillingSeatChange::withoutGlobalScope('team')
            ->where('invoice_id', $invoice->id)->where('status', BillingSeatChange::AWAITING_PAYMENT)
            ->update(['status' => BillingSeatChange::LAPSED]);
    }

    /**
     * Called by SubscriptionLifecycle as a period rolls over (inside its transaction, before the new period's
     * invoice is raised). Returns the subscription columns to change.
     *
     *  - an unpaid seat purchase from the ended period lapses and its open invoice is voided (a partly paid
     *    one is left alone, see docs/seat-purchasing.md);
     *  - a scheduled reduction is applied;
     *  - extra seats on a plan that has no extra-seat price for the new cycle are dropped, so seats are
     *    never carried for free.
     *
     * @return array<string, int|null>
     */
    public function onRenewal(BillingSubscription $sub, BillingPlan $newPlan, string $newCycle): array
    {
        $this->lapseUnpaid($sub);

        $seats = (int) $sub->extra_seats;
        $next = $sub->pending_extra_seats !== null ? (int) $sub->pending_extra_seats : $seats;

        if ($next !== $seats) {
            BillingSeatChange::withoutGlobalScope('team')
                ->where('subscription_id', $sub->id)->where('status', BillingSeatChange::SCHEDULED)
                ->update(['status' => BillingSeatChange::APPLIED, 'applied_at' => now()]);
            $this->audit->record('seats.reduction_applied', $sub->team_id, null, $sub, ['from' => $seats, 'to' => $next]);
        }

        if ($next > 0 && ! $newPlan->sellsExtraSeats($newCycle)) {
            $this->audit->record('seats.dropped_no_price', $sub->team_id, null, $sub, ['from' => $next, 'plan' => $newPlan->slug, 'cycle' => $newCycle]);
            $next = 0;
        }

        return ['extra_seats' => $next, 'pending_extra_seats' => null];
    }

    private function lapseUnpaid(BillingSubscription $sub): void
    {
        $waiting = BillingSeatChange::withoutGlobalScope('team')
            ->where('subscription_id', $sub->id)->where('status', BillingSeatChange::AWAITING_PAYMENT)->get();

        foreach ($waiting as $change) {
            $invoice = BillingInvoice::withoutGlobalScope('team')->lockForUpdate()->find($change->invoice_id);

            if ($invoice !== null && $invoice->amount_paid_cents > 0) {
                continue;
            }

            if ($invoice !== null && in_array($invoice->status, ['draft', 'open'], true)) {
                $invoice->forceFill(['status' => 'void', 'voided_at' => now()])->save();
                $this->audit->record('invoice.voided', $invoice->team_id, null, $invoice, [
                    'invoice_number' => $invoice->invoice_number, 'reason' => 'Seat purchase unpaid when the billing period ended.',
                ]);
            }

            $change->forceFill(['status' => BillingSeatChange::LAPSED])->save();
            $this->audit->record('seats.lapsed', $sub->team_id, null, $sub, ['seat_change_id' => $change->id]);
        }
    }

    private function supersedeScheduled(BillingSubscription $sub): void
    {
        BillingSeatChange::withoutGlobalScope('team')
            ->where('subscription_id', $sub->id)->where('status', BillingSeatChange::SCHEDULED)
            ->update(['status' => BillingSeatChange::SUPERSEDED]);
    }

    private function store(BillingSubscription $sub, string $key, string $kind, int $from, int $to, string $status, ?int $invoiceId, ?User $actor, bool $applied = false): BillingSeatChange
    {
        return BillingSeatChange::withoutGlobalScope('team')->create([
            'team_id' => $sub->team_id,
            'subscription_id' => $sub->id,
            'request_key' => $key,
            'kind' => $kind,
            'from_extra_seats' => $from,
            'to_extra_seats' => $to,
            'status' => $status,
            'invoice_id' => $invoiceId,
            'requested_by' => $actor?->id,
            'applied_at' => $applied ? now() : null,
        ]);
    }

    /**
     * @param  array{0: int, 1: int}|null  $days  [days left, days in the period] when pro rata
     */
    private function invoice(BillingSubscription $sub, Money $amount, Money $unit, int $quantity, ?array $days, CarbonInterface $now, CarbonInterface $periodEnd): BillingInvoice
    {
        $finalize = (bool) config('billing.invoice.auto_finalize', true);
        $bps = Money::decimalToCents((string) config('billing.invoice.tax_rate_percent', '0'));
        $tax = $amount->percentage($bps);

        // period_start/period_end stay null: unique (subscription_id, period_start) is what stops a billing
        // period being invoiced twice, and this invoice is not a billing period. The line item names its dates.
        $invoice = BillingInvoice::withoutGlobalScope('team')->create([
            'team_id' => $sub->team_id,
            'subscription_id' => $sub->id,
            'invoice_number' => $this->numbers->next($sub->team_id, $now),
            'status' => $finalize ? 'open' : 'draft',
            'currency' => $amount->currency,
            'subtotal_cents' => $amount->cents,
            'tax_cents' => $tax->cents,
            'total_cents' => $amount->add($tax)->cents,
            'tax_rate_bps' => $bps,
            'issued_at' => $finalize ? $now : null,
            'due_date' => $finalize ? $now->copy()->addDays((int) config('billing.invoice.payment_terms_days', 14)) : null,
        ]);

        $description = "Extra seats x {$quantity}".($days !== null ? ", pro rata {$days[0]} of {$days[1]} days" : '');

        $invoice->items()->create([
            'description' => $description,
            'quantity' => $quantity,
            'unit_price_cents' => $unit->cents,
            'amount_cents' => $amount->cents,
            'period' => $now->format('j M Y').' to '.$periodEnd->format('j M Y'),
        ]);

        $this->audit->record('invoice.generated', $sub->team_id, null, $invoice, [
            'invoice_number' => $invoice->invoice_number, 'total_cents' => $invoice->total_cents,
            'currency' => $invoice->currency, 'status' => $invoice->status, 'reason' => 'extra_seats',
        ]);

        return $invoice;
    }

    private function replay(BillingSeatChange $existing, int $target): BillingSeatChange
    {
        if ($existing->to_extra_seats !== $target) {
            throw BillingRuleViolation::because('idempotency_conflict', 'That request key was already used for a different seat count.');
        }

        $existing->replayed = true;

        return $existing;
    }
}
