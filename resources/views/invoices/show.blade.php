<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <div style="margin-bottom:1.5rem;">
        <a href="{{ route('dashboard') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
            Back to dashboard
        </a>
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">
                Invoice {{ $invoice->invoice_number }}
            </h1>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">Created {{ $invoice->created_at->format('l, F j, Y') }}</p>
        </div>
        <span style="font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;{{ $invoice->isPaid() ? 'background:rgba(34,197,94,0.1);color:#22c55e;' : ($invoice->isOverdue() ? 'background:rgba(239,68,68,0.1);color:#ef4444;' : 'background:rgba(245,158,11,0.1);color:#f59e0b;') }}">
            {{ ucfirst($invoice->status) }}{{ $invoice->isOverdue() ? ' · Overdue' : '' }}
        </span>
    </div>

    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.25rem;">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;">
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.5rem;">Subtotal</div>
                <div class="metric-val" style="font-size:1.1rem;font-weight:600;color:#f4f4f5;">{{ $invoice->currency }} {{ number_format($invoice->subtotal, 2) }}</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.5rem;">Tax</div>
                <div class="metric-val" style="font-size:1.1rem;font-weight:600;color:#f4f4f5;">{{ $invoice->currency }} {{ number_format($invoice->tax, 2) }}</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.5rem;">Total</div>
                <div class="metric-val" style="font-size:1.1rem;font-weight:700;color:#818cf8;">{{ $invoice->currency }} {{ number_format($invoice->total, 2) }}</div>
            </div>
        </div>
        <div style="display:flex;gap:2rem;margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,0.06);">
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.35rem;">Due</div>
                <div style="font-size:12px;color:#d4d4d8;">{{ $invoice->due_date?->format('M d, Y') ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.35rem;">Paid</div>
                <div style="font-size:12px;color:#d4d4d8;">{{ $invoice->paid_at?->format('M d, Y') ?? '—' }}</div>
            </div>
        </div>
    </div>

    <div class="dot-card" style="padding:1.5rem;">
        <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 1.25rem;">Line Items</h3>
        @if($invoice->items->isEmpty())
            <div style="text-align:center;padding:2rem 0;">
                <span class="material-symbols-rounded" style="font-size:36px;color:#3f3f46;display:block;margin-bottom:0.75rem;">receipt_long</span>
                <p style="font-size:0.8rem;color:#52525b;margin:0;">No line items recorded for this invoice.</p>
            </div>
        @else
            <div style="display:grid;gap:0.5rem;">
                @foreach($invoice->items as $item)
                <div style="display:flex;align-items:center;justify-content:space-between;padding:0.75rem 1rem;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
                    <div>
                        <div style="font-size:12px;font-weight:600;color:#d4d4d8;">{{ $item->description }}</div>
                        <div style="font-size:11px;color:#52525b;margin-top:2px;">
                            {{ $item->quantity }} × {{ number_format($item->unit_price, 2) }}{{ $item->period ? ' · ' . $item->period : '' }}
                        </div>
                    </div>
                    <div class="metric-val" style="font-size:13px;font-weight:600;color:#f4f4f5;">{{ number_format($item->amount, 2) }}</div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
</x-app-layout>
