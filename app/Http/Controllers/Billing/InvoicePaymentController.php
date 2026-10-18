<?php

namespace App\Http\Controllers\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\PaymentAttempt;
use App\Services\Payments\Paystack\PaystackCheckout;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackSettlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pay an invoice online with Paystack: start a payment (POST) and finish it when the payer comes back (GET).
 * Owners and admins of the invoice's company only (the manage-billing gate).
 */
class InvoicePaymentController extends Controller
{
    public function store(Request $request, BillingInvoice $invoice, PaystackCheckout $checkout): RedirectResponse
    {
        Gate::authorize('manage-billing', $invoice->team);

        try {
            $attempt = $checkout->start($invoice, $request->user());
        } catch (BillingRuleViolation|PaymentGatewayException $e) {
            return redirect()->route('invoices.show', $invoice)->with('billing_error', $e->getMessage());
        }

        return redirect()->away($attempt->authorization_url);
    }

    /**
     * Where Paystack sends the payer back. The address carries our attempt id; nothing in the query string
     * (status, reference, trxref) is read. The transaction is verified server side against Paystack using the
     * reference we stored, and only a confirmed match is applied.
     */
    public function callback(Request $request, string $attempt, PaystackClient $client, PaystackSettlement $settlement): RedirectResponse
    {
        $attempt = PaymentAttempt::withoutGlobalScope('team')->where('uuid', $attempt)->first();

        abort_unless($attempt && $request->user()->belongsToTeam($attempt->team), 404);
        Gate::authorize('manage-billing', $attempt->team);

        $invoice = $attempt->invoice;
        $back = redirect()->route('invoices.show', $invoice);

        try {
            $transaction = $client->verifyTransaction($attempt->reference);
        } catch (PaymentGatewayException) {
            return $back->with('billing_error', 'We could not confirm your payment with Paystack just now. If you completed it, it will appear on this invoice shortly; otherwise try Pay now again.');
        }

        $outcome = $settlement->settle($attempt, $transaction);

        if ($outcome->isSettled()) {
            return $back->with('billing_notice', 'Payment received. Thank you.');
        }

        if ($outcome->isHeld()) {
            return $back->with('billing_error', 'Paystack confirmed a payment, but it could not be applied to this invoice automatically. A platform operator will review it; nothing is lost.');
        }

        if ($outcome->transactionStatus === 'failed') {
            $attempt->forceFill(['status' => PaymentAttempt::FAILED, 'completed_at' => now(), 'failure_reason' => 'paystack_failed'])->save();

            return $back->with('billing_error', 'Paystack reports that this payment did not go through. Nothing was charged to the invoice; you can try again.');
        }

        return $back->with('billing_error', 'This payment was not completed. Nothing was charged to the invoice; you can try again.');
    }
}
