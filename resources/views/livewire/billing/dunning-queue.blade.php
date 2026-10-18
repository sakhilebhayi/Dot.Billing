<div>
    @if($error)
        <div class="state-line bad" role="alert" style="margin-bottom:1rem;"><span class="dot" aria-hidden="true"></span><span>{{ $error }}</span></div>
    @endif

    @if ($this->cases->isEmpty())
        <div class="state-line ok" role="status"><span class="dot" aria-hidden="true"></span><span>No dunning cases need review.</span></div>
    @endif

    @foreach ($this->cases as $case)
        <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1rem;" wire:key="case-{{ $case->id }}">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                <div>
                    <div style="font-family:'Syne',sans-serif;font-weight:700;color:#f4f4f5;">{{ $case->team->name }}</div>
                    <div class="dot-muted" style="margin-top:0.2rem;">
                        {{ $case->reason === 'invoice_overdue' ? 'Invoice overdue' : 'Payment failed' }}
                        @if($case->invoice)
                            &middot; <a href="{{ route('invoices.show', $case->invoice) }}" class="row-link" style="font-family:'JetBrains Mono',monospace;color:#d4d4d8;">{{ $case->invoice->invoice_number }}</a>
                            &middot; <span class="num">{{ $case->invoice->balanceMoney()->format() }}</span>
                            @if($case->invoice->due_date)&middot; due {{ $case->invoice->due_date->format('j M Y') }}@endif
                        @endif
                    </div>
                </div>
                @if($case->status === 'grace')
                    <span class="pill grace">In grace until {{ $case->grace_until->format('j M Y') }}</span>
                @else
                    <span class="pill overdue">Needs review</span>
                @endif
            </div>
            <div class="btn-row" style="margin-top:0.9rem;">
                <button type="button" wire:click="extend({{ $case->id }}, 7)" wire:confirm="Grant 7 more days of grace? The invoice stays open and stops counting as overdue until then; the case comes back here if it is still unpaid."
                    class="dot-btn dot-btn-ghost" style="padding:4px 12px;font-size:12px;">
                    {{ $case->status === 'grace' ? 'Extend grace 7 days' : 'Grant 7 days grace' }}
                </button>
                <button type="button" wire:click="cancelSubscription({{ $case->id }})" wire:confirm="Cancel the subscription this invoice belongs to, immediately?"
                    class="dot-btn dot-btn-danger" style="padding:4px 12px;font-size:12px;">
                    Cancel subscription
                </button>
                <button type="button" wire:click="dismiss({{ $case->id }})" class="dot-btn dot-btn-ghost" style="padding:4px 12px;font-size:12px;">
                    Dismiss
                </button>
            </div>
        </div>
    @endforeach
</div>
