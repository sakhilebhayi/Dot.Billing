<?php

namespace App\Services\Payments\Paystack;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingInvoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Services\Billing\AuditLog;
use App\Services\Billing\RecordPayment;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single place a Paystack payment becomes (or fails to become) money on an invoice. The redirect back
 * and the webhook both end here, so they cannot disagree and cannot both apply:
 *
 *  - the attempt row is locked, so two deliveries for one attempt run one after the other;
 *  - the payment goes through RecordPayment under provider "paystack" and the transaction id, so
 *    unique(provider, external_id) makes a replay harmless even if the lock is bypassed;
 *  - the transaction must match the attempt on reference, amount and currency and report "success";
 *  - anything else is written as an operator-visible held event (status unmatched or needs_review) with a
 *    reason code and changes nothing on the invoice. Business conditions never raise: the caller answers 200.
 */
class PaystackSettlement
{
    public const PROVIDER = 'paystack';

    public function __construct(private RecordPayment $payments, private AuditLog $audit) {}

    public function settle(PaymentAttempt $attempt, PaystackTransaction $tx, string $eventName = 'charge.success'): SettlementOutcome
    {
        return DB::transaction(function () use ($attempt, $tx, $eventName) {
            $locked = PaymentAttempt::withoutGlobalScope('team')->lockForUpdate()->findOrFail($attempt->id);

            $mismatch = match (true) {
                ! hash_equals($locked->reference, $tx->reference) => 'reference_mismatch',
                $tx->amountCents !== $locked->amount_cents => 'amount_mismatch',
                $tx->currency !== strtoupper($locked->currency) => 'currency_mismatch',
                default => null,
            };

            if ($mismatch !== null) {
                return new SettlementOutcome(SettlementOutcome::HELD, $this->hold($locked, $tx, $eventName, PaymentEvent::UNMATCHED, $mismatch));
            }

            if (! $tx->isSuccessful()) {
                return new SettlementOutcome(SettlementOutcome::NOT_SUCCESSFUL, null, $tx->status);
            }

            $invoice = BillingInvoice::withoutGlobalScope('team')->findOrFail($locked->invoice_id);

            if ($locked->status === PaymentAttempt::SUCCEEDED) {
                return new SettlementOutcome(SettlementOutcome::REPLAYED, $this->appliedEvent($tx->id));
            }

            try {
                $result = $this->payments->apply(
                    $invoice,
                    new Money($tx->amountCents, $tx->currency),
                    provider: self::PROVIDER,
                    externalId: $tx->id,
                    method: 'paystack',
                    payload: $tx->safePayload($eventName),
                    actor: null,
                    receivedAt: now(),
                );
            } catch (BillingRuleViolation $e) {
                $locked->forceFill(['status' => PaymentAttempt::NEEDS_REVIEW, 'failure_reason' => $e->reason, 'completed_at' => now()])->save();

                return new SettlementOutcome(SettlementOutcome::HELD, $this->hold($locked, $tx, $eventName, PaymentEvent::NEEDS_REVIEW, $e->reason));
            }

            $locked->forceFill(['status' => PaymentAttempt::SUCCEEDED, 'completed_at' => now(), 'failure_reason' => null])->save();

            return new SettlementOutcome($result->replayed ? SettlementOutcome::REPLAYED : SettlementOutcome::APPLIED, $result->event);
        });
    }

    /**
     * A charge.success body whose own status says it did not succeed: held for an operator, nothing applied.
     */
    public function holdNotSuccessful(PaymentAttempt $attempt, PaystackTransaction $tx, string $eventName): PaymentEvent
    {
        return $this->hold($attempt, $tx, $eventName, PaymentEvent::UNMATCHED, 'status_not_success');
    }

    /**
     * Record an event no attempt matches (unknown reference, or a refund for an unknown transaction).
     *
     * @param  array<string, mixed>  $payload
     */
    public function holdUnknown(string $type, string $key, string $reason, int $amountCents, string $currency, array $payload, string $status = PaymentEvent::UNMATCHED): PaymentEvent
    {
        return $this->storeHeld(
            externalId: $key,
            attributes: [
                'team_id' => null, 'invoice_id' => null, 'type' => $type, 'status' => $status, 'reason' => $reason,
                'amount_cents' => $amountCents, 'currency' => strtoupper($currency), 'payload' => $payload,
            ],
        );
    }

    /**
     * A refund Paystack reports for a transaction we recognise. The ledger has no refund concept, so it is
     * recorded for an operator and the invoice is left exactly as it is.
     *
     * @param  array<string, mixed>  $payload
     */
    public function holdRefund(PaymentAttempt $attempt, string $key, int $amountCents, string $currency, array $payload): PaymentEvent
    {
        $event = $this->storeHeld(
            externalId: $key,
            attributes: [
                'team_id' => $attempt->team_id, 'invoice_id' => $attempt->invoice_id, 'type' => 'refund',
                'status' => PaymentEvent::NEEDS_REVIEW, 'reason' => 'refund_not_modelled',
                'amount_cents' => $amountCents, 'currency' => strtoupper($currency), 'payload' => $payload,
            ],
            audit: fn (PaymentEvent $e) => $this->audit->record('payment.refund_flagged', $attempt->team_id, null, $attempt->invoice, [
                'event_id' => $e->id, 'amount_cents' => $amountCents, 'currency' => strtoupper($currency), 'reference' => $attempt->reference,
            ]),
        );

        return $event;
    }

    private function hold(PaymentAttempt $attempt, PaystackTransaction $tx, string $eventName, string $status, string $reason): PaymentEvent
    {
        return $this->storeHeld(
            externalId: $tx->id,
            attributes: [
                'team_id' => $attempt->team_id, 'invoice_id' => $attempt->invoice_id, 'type' => 'payment',
                'status' => $status, 'reason' => $reason,
                'amount_cents' => $tx->amountCents, 'currency' => $tx->currency, 'payload' => $tx->safePayload($eventName),
            ],
            audit: fn (PaymentEvent $e) => $this->audit->record('payment.held', $attempt->team_id, null, $attempt->invoice, [
                'event_id' => $e->id, 'status' => $status, 'reason' => $reason, 'amount_cents' => $tx->amountCents, 'currency' => $tx->currency, 'reference' => $attempt->reference,
            ]),
        );
    }

    /**
     * Insert a held event, or return the one already stored for the same provider event. The "held:" prefix
     * keeps these out of the way of the real payment's (provider, external_id), so a held event never
     * blocks that payment from being applied later.
     *
     * @param  array<string, mixed>  $attributes
     * @param  (callable(PaymentEvent): mixed)|null  $audit
     */
    private function storeHeld(string $externalId, array $attributes, ?callable $audit = null): PaymentEvent
    {
        $key = 'held:'.($attributes['type'] ?? 'payment').':'.$externalId;

        if ($existing = PaymentEvent::withoutGlobalScope('team')->where('provider', self::PROVIDER)->where('external_id', $key)->first()) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($key, $attributes, $audit) {
                $event = PaymentEvent::withoutGlobalScope('team')->create($attributes + [
                    'provider' => self::PROVIDER, 'external_id' => $key, 'received_at' => now(),
                ]);

                if ($audit !== null) {
                    $audit($event);
                }

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            return PaymentEvent::withoutGlobalScope('team')->where('provider', self::PROVIDER)->where('external_id', $key)->firstOrFail();
        }
    }

    private function appliedEvent(string $transactionId): ?PaymentEvent
    {
        return PaymentEvent::withoutGlobalScope('team')
            ->where('provider', self::PROVIDER)->where('external_id', $transactionId)
            ->where('status', PaymentEvent::APPLIED)
            ->first();
    }
}
