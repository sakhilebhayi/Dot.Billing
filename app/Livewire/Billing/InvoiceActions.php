<?php

namespace App\Livewire\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingInvoice;
use App\Services\Billing\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Finalize a draft or void an unpaid invoice. Owners and admins only; both are audited.
 */
class InvoiceActions extends Component
{
    public BillingInvoice $invoice;

    public string $voidReason = '';

    public function mount(BillingInvoice $invoice): void
    {
        $this->invoice = $invoice;
    }

    public function finalize(InvoiceService $service): void
    {
        Gate::authorize('manage-billing', $this->invoice->team);
        $this->resetErrorBag();

        try {
            $this->invoice = $service->finalize($this->invoice, Auth::user());
            $this->redirect(route('invoices.show', $this->invoice), navigate: true);
        } catch (BillingRuleViolation $e) {
            $this->addError('invoice', $e->getMessage());
        }
    }

    public function void(InvoiceService $service): void
    {
        Gate::authorize('manage-billing', $this->invoice->team);
        $this->resetErrorBag();

        $this->validate(['voidReason' => ['required', 'string', 'min:3', 'max:200']], [
            'voidReason.required' => 'Say why this invoice is being voided.',
            'voidReason.min' => 'Say why this invoice is being voided.',
        ]);

        try {
            $this->invoice = $service->void($this->invoice, Auth::user(), $this->voidReason);
            $this->redirect(route('invoices.show', $this->invoice), navigate: true);
        } catch (BillingRuleViolation $e) {
            $this->addError('invoice', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.billing.invoice-actions');
    }
}
