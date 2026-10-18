<?php

namespace App\Services\Payments\Paystack;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\BillingInvoice;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Billing\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Pay now": start (or reopen) one online payment for an open invoice.
 *
 * Inside one transaction with the invoice row locked it
 *  1. refuses anything but an open invoice with a balance;
 *  2. retires attempts that expired or were made for a balance that has since changed;
 *  3. reuses the one live attempt if it is still good (a double click opens the same checkout);
 *  4. otherwise creates the attempt for the balance as it is right now and asks Paystack for a checkout.
 *
 * If Paystack cannot be reached the whole transaction rolls back: no attempt, no audit row, no state
 * change. The invoice itself is never modified here; only a confirmed payment (PaystackSettlement) does that.
 */
class PaystackCheckout
{
    public function __construct(
        private PaystackConfig $config,
        private PaystackClient $client,
        private AuditLog $audit,
    ) {}

    /**
     * @throws BillingRuleViolation when the invoice cannot be paid
     * @throws PaymentGatewayException when online payment is off or Paystack fails
     */
    public function start(BillingInvoice $invoice, User $payer): PaymentAttempt
    {
        if (! $this->config->canTakePayments()) {
            throw PaymentGatewayException::because('not_configured', (string) $this->config->notice());
        }

        return DB::transaction(function () use ($invoice, $payer) {
            $locked = BillingInvoice::withoutGlobalScope('team')->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status !== 'open') {
                throw BillingRuleViolation::because('invoice_not_payable', "A {$locked->status} invoice cannot be paid.");
            }

            $balance = $locked->balanceMoney();

            if (! $balance->isPositive()) {
                throw BillingRuleViolation::because('nothing_owing', 'Nothing is owing on this invoice.');
            }

            $live = PaymentAttempt::withoutGlobalScope('team')->lockForUpdate()
                ->where('invoice_id', $locked->id)->where('status', PaymentAttempt::INITIATED)
                ->get();

            foreach ($live as $existing) {
                if ($existing->isLive() && $existing->amount_cents === $balance->cents && strtoupper($existing->currency) === $balance->currency && $existing->authorization_url) {
                    return $existing;
                }

                $existing->forceFill(['status' => PaymentAttempt::EXPIRED, 'completed_at' => now(), 'failure_reason' => $existing->isLive() ? 'superseded' : 'expired'])->save();
            }

            $attempt = PaymentAttempt::withoutGlobalScope('team')->create([
                'team_id' => $locked->team_id,
                'invoice_id' => $locked->id,
                'provider' => PaystackSettlement::PROVIDER,
                'reference' => 'dbi'.$locked->id.'-'.Str::lower((string) Str::ulid()),
                'amount_cents' => $balance->cents,
                'currency' => $balance->currency,
                'status' => PaymentAttempt::INITIATED,
                'initiated_by' => $payer->id,
                'expires_at' => now()->addMinutes($this->config->attemptTtlMinutes()),
            ]);

            $attempt->setRelation('invoice', $locked);
            $checkout = $this->client->initializeTransaction($attempt, $payer->email);

            $attempt->forceFill(['authorization_url' => $checkout->authorizationUrl])->save();

            $this->audit->record('payment.initiated', $locked->team_id, $payer, $locked, [
                'invoice_number' => $locked->invoice_number,
                'attempt_id' => $attempt->id,
                'reference' => $attempt->reference,
                'amount_cents' => $attempt->amount_cents,
                'currency' => $attempt->currency,
                'provider' => PaystackSettlement::PROVIDER,
            ]);

            return $attempt;
        });
    }
}
