<?php

namespace App\Services\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\DunningCase;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Support\Money;
use App\Support\PayloadScrubber;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Applies one payment to one invoice. This is the single entry for money arriving, and the seam a
 * payment-provider adapter plugs into: the adapter verifies its own webhook, then calls apply() with
 * the provider name and the provider's event id. No provider code lives in this app.
 *
 * Guarantees, all inside one transaction with the invoice row locked:
 *  - replay: the same (provider, external_id) twice is a no-op that returns the first result;
 *  - the same id with different facts is a payment_conflict, never silently applied;
 *  - partial payments are allowed; an overpayment is rejected; only an open invoice can be paid;
 *  - the invoice becomes paid exactly when the balance reaches zero;
 *  - the stored payload has secrets scrubbed, and an audit row names the actor.
 *
 * Only type "payment" exists today; refunds and chargebacks are deliberately not modelled yet.
 */
class RecordPayment
{
    public function __construct(private AuditLog $audit, private SeatChange $seats) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function apply(
        BillingInvoice $invoice,
        Money $amount,
        ?string $provider = null,
        ?string $externalId = null,
        string $method = 'manual',
        array $payload = [],
        ?User $actor = null,
        ?CarbonInterface $receivedAt = null,
        string $type = 'payment',
    ): PaymentResult {
        if ($type !== 'payment') {
            throw BillingRuleViolation::because('unsupported_type', 'Only payments can be recorded; refunds are not supported yet.');
        }

        if (($provider === null) !== ($externalId === null) || $provider === '' || $externalId === '') {
            throw BillingRuleViolation::because('invalid_reference', 'A provider and its external id must be given together.');
        }

        if (! $amount->isPositive()) {
            throw BillingRuleViolation::because('invalid_amount', 'The amount must be greater than zero.');
        }

        $keyed = $provider !== null;

        if ($keyed && ($existing = $this->existing($provider, $externalId))) {
            return $this->replay($existing, $invoice, $amount);
        }

        try {
            return DB::transaction(fn () => $this->record($invoice, $amount, $provider, $externalId, $method, $payload, $actor, $receivedAt ?? now()));
        } catch (UniqueConstraintViolationException $e) {
            // Two deliveries of the same event raced; the loser lands here and becomes a replay.
            if ($keyed && ($existing = $this->existing($provider, $externalId))) {
                return $this->replay($existing, $invoice, $amount);
            }

            throw $e;
        }
    }

    private function record(BillingInvoice $invoice, Money $amount, ?string $provider, ?string $externalId, string $method, array $payload, ?User $actor, CarbonInterface $receivedAt): PaymentResult
    {
        $locked = BillingInvoice::withoutGlobalScope('team')->lockForUpdate()->findOrFail($invoice->id);

        // Re-check under the lock: a concurrent delivery may have just recorded this event.
        if ($provider !== null && ($existing = $this->existing($provider, $externalId))) {
            return $this->replay($existing, $locked, $amount);
        }

        if ($locked->status !== 'open') {
            throw BillingRuleViolation::because('invoice_not_payable', "A {$locked->status} invoice cannot be paid.");
        }

        if ($amount->currency !== strtoupper($locked->currency)) {
            throw BillingRuleViolation::because('currency_mismatch', "This invoice is in {$locked->currency}; the payment is in {$amount->currency}.");
        }

        $balance = $locked->balanceMoney();

        if ($amount->greaterThan($balance)) {
            throw BillingRuleViolation::because('overpayment', "That is more than the {$balance->format()} still owing.");
        }

        $payment = BillingPayment::create([
            'team_id' => $locked->team_id,
            'invoice_id' => $locked->id,
            'amount_cents' => $amount->cents,
            'currency' => $amount->currency,
            'status' => 'succeeded',
            'method' => $method,
            'processed_at' => $receivedAt,
        ]);

        $event = PaymentEvent::create([
            'team_id' => $locked->team_id,
            'invoice_id' => $locked->id,
            'payment_id' => $payment->id,
            'provider' => $provider,
            'external_id' => $externalId,
            'type' => 'payment',
            'amount_cents' => $amount->cents,
            'currency' => $amount->currency,
            'payload' => $payload === [] ? null : PayloadScrubber::scrub($payload),
            'received_at' => $receivedAt,
        ]);

        $paidInFull = $balance->cents === $amount->cents;

        $locked->forceFill([
            'amount_paid_cents' => $locked->amount_paid_cents + $amount->cents,
            ...($paidInFull ? ['status' => 'paid', 'paid_at' => $receivedAt, 'grace_until' => null] : []),
        ])->save();

        $this->audit->record('payment.recorded', $locked->team_id, $actor, $locked, array_filter([
            'invoice_number' => $locked->invoice_number,
            'payment_id' => $payment->id,
            'amount_cents' => $amount->cents,
            'currency' => $amount->currency,
            'method' => $method,
            'provider' => $provider,
            'external_id' => $externalId,
            'reference' => is_string($payload['reference'] ?? null) ? $payload['reference'] : null,
        ], fn ($v) => $v !== null));

        if ($paidInFull) {
            $this->audit->record('invoice.paid', $locked->team_id, $actor, $locked, ['invoice_number' => $locked->invoice_number]);

            // A paid seat purchase is the moment its seats start counting (see SeatChange::settleInvoice).
            $this->seats->settleInvoice($locked);

            DunningCase::where('invoice_id', $locked->id)
                ->whereIn('status', ['open', 'grace'])
                ->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_notes' => 'Invoice paid in full.']);
        }

        return new PaymentResult($payment, $event, false);
    }

    private function existing(string $provider, string $externalId): ?PaymentEvent
    {
        return PaymentEvent::withoutGlobalScope('team')
            ->where('provider', $provider)->where('external_id', $externalId)
            ->first();
    }

    private function replay(PaymentEvent $existing, BillingInvoice $invoice, Money $amount): PaymentResult
    {
        if ($existing->invoice_id !== $invoice->id
            || $existing->amount_cents !== $amount->cents
            || strtoupper($existing->currency) !== $amount->currency) {
            throw BillingRuleViolation::because('payment_conflict', 'That provider event id was already recorded with different details.');
        }

        return new PaymentResult($existing->payment()->withoutGlobalScope('team')->firstOrFail(), $existing, true);
    }
}
