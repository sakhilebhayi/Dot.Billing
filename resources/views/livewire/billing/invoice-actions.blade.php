<div>
    @error('invoice')<div class="field-error" role="alert" style="margin-bottom:8px;">{{ $message }}</div>@enderror

    @if($invoice->status === 'draft')
        <button type="button" wire:click="finalize" wire:confirm="Finalize this invoice? It gets an issue date and becomes visible to the team." class="dot-btn dot-btn-primary">Finalize invoice</button>
    @endif

    @if(in_array($invoice->status, ['draft', 'open']) && $invoice->amount_paid_cents === 0)
        <form wire:submit="void" style="display:inline-flex;gap:8px;align-items:flex-start;flex-wrap:wrap;{{ $invoice->status === 'draft' ? 'margin-left:8px;' : '' }}">
            <div>
                <input type="text" wire:model="voidReason" class="dot-input" style="width:15rem;" placeholder="Reason for voiding" aria-label="Reason for voiding" maxlength="200">
                @error('voidReason')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="dot-btn dot-btn-danger" wire:confirm="Void this invoice? This cannot be undone.">Void invoice</button>
        </form>
    @endif
</div>
