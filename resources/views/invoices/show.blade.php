<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <div style="margin-bottom:1.5rem;">
        <a href="{{ route('invoices.index') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
            All invoices
        </a>
    </div>

    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.4rem;letter-spacing:-0.01em;">Invoice {{ $invoice->invoice_number }}</h1>
            <div style="display:flex;gap:10px;align-items:center;">
                <x-billing.invoice-status :invoice="$invoice" />
                <span class="dot-muted">{{ $invoice->issued_at ? 'Issued '.$invoice->issued_at->format('j M Y') : 'Not yet issued' }}</span>
            </div>
        </div>
        <div class="btn-row">
            <a href="{{ route('invoices.print', $invoice) }}" target="_blank" rel="noopener" class="dot-btn dot-btn-ghost">
                <span class="material-symbols-rounded" style="font-size:16px;">print</span>
                Print view
            </a>
        </div>
    </div>

    @if($canManage && in_array($invoice->status, ['draft', 'open']))
        <div style="margin-bottom:1.25rem;">
            <livewire:billing.invoice-actions :invoice="$invoice" />
        </div>
    @endif

    <div class="dot-card" style="padding:0.5rem 1.5rem;margin-bottom:1.25rem;">
        <div class="readout">
            <div class="k">Customer</div>
            <div class="v" style="font-family:'Inter',sans-serif;">{{ $invoice->team->name }}</div>
            <div class="k">Due</div>
            <div class="v">{{ $invoice->due_date?->format('j M Y') ?? '—' }}</div>
            <div class="k">Billing period</div>
            <div class="v">{{ $invoice->period_start && $invoice->period_end ? $invoice->period_start->format('j M Y').' to '.$invoice->period_end->format('j M Y') : '—' }}</div>
            <div class="k">Paid</div>
            <div class="v">{{ $invoice->paid_at?->format('j M Y') ?? '—' }}</div>
        </div>
    </div>

    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.25rem;">
        <h3 class="dot-h" style="margin-bottom:1rem;">Line items</h3>
        @if($invoice->items->isEmpty())
            <p class="dot-muted" style="margin:0;">No line items were recorded for this invoice.</p>
        @else
            <div class="table-scroll">
                <table class="dot-table">
                    <thead><tr><th>Description</th><th>Period</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                    @foreach($invoice->items as $item)
                        <tr>
                            <td>{{ $item->description }}</td>
                            <td class="dot-muted">{{ $item->period ?? '—' }}</td>
                            <td class="num">{{ $item->quantity }}</td>
                            <td class="num">{{ (new \App\Support\Money((int) $item->unit_price_cents, $invoice->currency))->format() }}</td>
                            <td class="num">{{ (new \App\Support\Money((int) $item->amount_cents, $invoice->currency))->format() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="readout" style="margin-top:1rem;border-top:1px solid rgba(255,255,255,0.1);grid-template-columns:1fr auto;">
            <div class="k" style="text-align:right;padding-right:1.5rem;">Subtotal</div><div class="v">{{ $invoice->subtotalMoney()->format() }}</div>
            <div class="k" style="text-align:right;padding-right:1.5rem;">Tax{{ $invoice->tax_rate_bps > 0 ? ' ('.rtrim(rtrim(number_format($invoice->tax_rate_bps / 100, 2), '0'), '.').'%)' : '' }}</div><div class="v">{{ $invoice->taxMoney()->format() }}</div>
            <div class="k" style="text-align:right;padding-right:1.5rem;">Total</div><div class="v" style="font-weight:700;color:#818cf8;">{{ $invoice->totalMoney()->format() }}</div>
            <div class="k" style="text-align:right;padding-right:1.5rem;">Paid</div><div class="v">{{ $invoice->amountPaidMoney()->format() }}</div>
            <div class="k" style="text-align:right;padding-right:1.5rem;">Balance due</div><div class="v" style="font-weight:700;">{{ $invoice->status === 'void' ? '—' : $invoice->balanceMoney()->format() }}</div>
        </div>
    </div>
</div>
</x-app-layout>
