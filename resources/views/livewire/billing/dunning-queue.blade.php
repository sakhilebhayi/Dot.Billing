<div>
    @if ($this->cases->isEmpty())
        <div class="dot-card" style="padding:1.5rem;text-align:center;color:#52525b;font-size:0.85rem;">
            No open dunning cases.
        </div>
    @endif

    @foreach ($this->cases as $case)
        <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <div style="font-family:'Syne',sans-serif;font-weight:700;color:#f4f4f5;">{{ $case->team->name }}</div>
                    <div style="font-size:0.78rem;color:#52525b;margin-top:0.2rem;">
                        {{ $case->reason === 'invoice_overdue' ? 'Invoice overdue' : 'Payment failed' }}
                        @if($case->invoice) — {{ $case->invoice->invoice_number }} (R{{ number_format((float) $case->invoice->total, 2) }}) @endif
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:0.5rem;margin-top:0.9rem;">
                <button wire:click="extend({{ $case->id }}, 7)" wire:confirm="Extend the grace period by 7 days?"
                    style="font-size:0.72rem;font-weight:600;padding:0.35rem 0.85rem;border-radius:9999px;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.35);color:#4ade80;cursor:pointer;">
                    Extend 7 days
                </button>
                <button wire:click="cancelSubscription({{ $case->id }})" wire:confirm="Cancel this team's subscription?"
                    style="font-size:0.72rem;font-weight:600;padding:0.35rem 0.85rem;border-radius:9999px;background:rgba(244,63,94,0.1);border:1px solid rgba(244,63,94,0.3);color:#fb7185;cursor:pointer;">
                    Cancel subscription
                </button>
                <button wire:click="dismiss({{ $case->id }})"
                    style="font-size:0.72rem;font-weight:600;padding:0.35rem 0.85rem;border-radius:9999px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#a1a1aa;cursor:pointer;">
                    Dismiss
                </button>
            </div>
        </div>
    @endforeach
</div>
