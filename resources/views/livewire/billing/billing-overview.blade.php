<div class="dot-card" style="padding:1.5rem;">
    <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 1.25rem;">Current Plan</h3>
    <div wire:loading.delay class="dot-loading-overlay">
        <span class="material-symbols-rounded dot-spin" style="font-size:22px;color:#818cf8;">progress_activity</span>
    </div>
    <div wire:loading.remove.delay>
    @if($this->subscription)
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
            <div>
                <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#818cf8;">{{ $this->subscription->plan->name ?? 'Unknown' }}</div>
                <div style="font-size:11px;color:#71717a;margin-top:4px;">{{ ucfirst($this->subscription->billing_cycle) }} billing · {{ ucfirst($this->subscription->status) }}</div>
                @if($this->subscription->isTrialing())
                    <div style="margin-top:6px;font-size:11px;color:#f59e0b;">Trial ends {{ $this->subscription->trial_ends_at->format('M d, Y') }}</div>
                @endif
            </div>
            @if($this->nextInvoice)
            <div style="text-align:right;">
                <div style="font-size:10px;color:#52525b;text-transform:uppercase;letter-spacing:0.08em;">Next Invoice</div>
                <div class="metric-val" style="font-size:1.5rem;font-weight:600;color:#f4f4f5;margin-top:2px;">{{ $this->nextInvoice->currency }} {{ number_format($this->nextInvoice->total, 2) }}</div>
                <div style="font-size:11px;color:#71717a;">Due {{ $this->nextInvoice->due_date?->format('M d, Y') ?? 'TBD' }}</div>
            </div>
            @endif
        </div>
    @else
        <div style="text-align:center;padding:2rem 0;">
            <span class="material-symbols-rounded" style="font-size:36px;color:#3f3f46;display:block;margin-bottom:0.75rem;">receipt_long</span>
            <p style="font-size:0.8rem;color:#52525b;margin:0;">No active subscription. Choose a plan to get started.</p>
        </div>
    @endif
    </div>
</div>
