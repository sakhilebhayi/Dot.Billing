<?php

namespace App\Services\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Invoice status transitions that are not payments: draft -> open (finalize) and draft/open -> void.
 * Payment transitions (open -> paid) live in RecordPayment. Authorization is the caller's job.
 */
class InvoiceService
{
    public function __construct(private AuditLog $audit, private SeatChange $seats) {}

    public function finalize(BillingInvoice $invoice, ?User $actor = null): BillingInvoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = $this->lock($invoice);

            if ($invoice->status !== 'draft') {
                throw BillingRuleViolation::because('invalid_state', 'Only a draft invoice can be finalized.');
            }

            $invoice->forceFill([
                'status' => 'open',
                'issued_at' => now(),
                'due_date' => $invoice->due_date ?? now()->addDays((int) config('billing.invoice.payment_terms_days', 14)),
            ])->save();

            $this->audit->record('invoice.finalized', $invoice->team_id, $actor, $invoice, ['invoice_number' => $invoice->invoice_number]);

            return $invoice;
        });
    }

    public function void(BillingInvoice $invoice, ?User $actor, string $reason): BillingInvoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason) {
            $invoice = $this->lock($invoice);

            if (! in_array($invoice->status, ['draft', 'open'], true) || $invoice->amount_paid_cents > 0) {
                throw BillingRuleViolation::because('invalid_state', 'Only an unpaid draft or open invoice can be voided.');
            }

            $invoice->forceFill(['status' => 'void', 'voided_at' => now()])->save();

            $this->audit->record('invoice.voided', $invoice->team_id, $actor, $invoice, [
                'invoice_number' => $invoice->invoice_number,
                'reason' => $reason,
            ]);

            $this->seats->invoiceVoided($invoice);

            return $invoice;
        });
    }

    private function lock(BillingInvoice $invoice): BillingInvoice
    {
        return BillingInvoice::withoutGlobalScope('team')->lockForUpdate()->findOrFail($invoice->id);
    }
}
