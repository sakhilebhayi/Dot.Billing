<form wire:submit="record" class="dot-card" style="padding:1.5rem;margin-top:1.25rem;">
    <h3 class="dot-h">Record a manual payment</h3>
    <p class="dot-muted" style="margin:6px 0 1rem;max-width:40rem;">Use this when money reached you outside any payment provider. It is applied to this invoice, the invoice closes when the balance reaches zero, and the entry is logged with your name.</p>

    <div class="form-grid" style="max-width:46rem;">
        <div>
            <label class="dot-label" for="pay-amount">Amount ({{ $invoice->currency }})</label>
            <input id="pay-amount" type="text" inputmode="decimal" wire:model="amount" class="dot-input num" style="text-align:left;" autocomplete="off">
            @error('amount')<div class="field-error" role="alert">{{ $message }}</div>@enderror
        </div>
        <div>
            <label class="dot-label" for="pay-method">Method</label>
            <select id="pay-method" wire:model="method" class="dot-input">
                @foreach(\App\Livewire\Billing\RecordPaymentForm::METHODS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('method')<div class="field-error" role="alert">{{ $message }}</div>@enderror
        </div>
        <div class="wide">
            <label class="dot-label" for="pay-ref">Reference (optional)</label>
            <input id="pay-ref" type="text" wire:model="reference" class="dot-input" maxlength="120" placeholder="Bank reference or receipt number">
            @error('reference')<div class="field-error" role="alert">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="btn-row" style="margin-top:1.1rem;">
        <button type="submit" class="dot-btn dot-btn-primary" wire:loading.attr="disabled" wire:target="record">Record payment</button>
        @if($notice)<span class="notice" role="status" style="margin:0;">{{ $notice }}</span>@endif
    </div>
</form>
