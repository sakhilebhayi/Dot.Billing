<?php

namespace App\Livewire\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingInvoice;
use App\Services\Billing\RecordPayment;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * "Record a manual payment": a team manager tells the system money arrived outside any provider
 * (EFT, bank transfer, cash). Goes through RecordPayment like any future provider adapter would.
 *
 * The form carries a request token that becomes the external id under provider "manual", so a
 * double click or a retried request replays instead of recording twice. It is regenerated after
 * each successful record so a second genuine payment is possible.
 */
class RecordPaymentForm extends Component
{
    public const METHODS = [
        'eft' => 'EFT',
        'bank_transfer' => 'Bank transfer',
        'cash' => 'Cash',
        'other' => 'Other',
    ];

    public BillingInvoice $invoice;

    public string $amount = '';

    public string $method = 'eft';

    public string $reference = '';

    public string $requestToken = '';

    public ?string $notice = null;

    public function mount(BillingInvoice $invoice): void
    {
        $this->invoice = $invoice;
        $this->prepare();
    }

    private function prepare(): void
    {
        $this->amount = Money::centsToDecimal(max(0, $this->invoice->balanceMoney()->cents));
        $this->requestToken = (string) Str::uuid();
    }

    public function record(RecordPayment $payments): void
    {
        Gate::authorize('manage-billing', $this->invoice->team);
        $this->resetErrorBag();
        $this->notice = null;

        $this->validate([
            'method' => ['required', 'in:'.implode(',', array_keys(self::METHODS))],
            'reference' => ['nullable', 'string', 'max:120'],
            'requestToken' => ['required', 'string', 'max:64'],
        ]);

        try {
            $money = Money::parse($this->amount, $this->invoice->currency);

            $result = $payments->apply(
                $this->invoice,
                $money,
                provider: 'manual',
                externalId: $this->requestToken,
                method: $this->method,
                payload: array_filter(['reference' => trim($this->reference) ?: null]),
                actor: Auth::user(),
            );
        } catch (BillingRuleViolation $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->invoice = BillingInvoice::withoutGlobalScope('team')->findOrFail($this->invoice->id);
        $this->notice = $result->replayed ? 'That payment was already recorded.' : 'Payment recorded.';
        $this->reference = '';
        $this->prepare();

        if ($this->invoice->status !== 'open') {
            $this->redirect(route('invoices.show', $this->invoice), navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.billing.record-payment-form');
    }
}
