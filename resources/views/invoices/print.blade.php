<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e7e7e4; color: #111; font: 13px/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .sheet { max-width: 800px; margin: 24px auto; background: #fff; padding: 56px 56px 64px; box-shadow: 0 1px 6px rgba(0,0,0,.15); }
        .bar { max-width: 800px; margin: 16px auto 0; display: flex; justify-content: space-between; align-items: center; font-size: 12px; }
        .bar a, .bar button { font: inherit; color: #111; background: #fff; border: 1px solid #bbb; border-radius: 6px; padding: 6px 12px; text-decoration: none; cursor: pointer; }
        h1 { font-size: 22px; letter-spacing: .02em; margin: 0 0 4px; text-transform: uppercase; }
        .meta { color: #555; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin: 32px 0; }
        .label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .1em; color: #666; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #555; padding: 0 8px 6px 0; border-bottom: 2px solid #111; }
        td { padding: 9px 8px 9px 0; border-bottom: 1px solid #ddd; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        th.num { padding-right: 0; } td.num:last-child { padding-right: 0; }
        .totals { margin: 18px 0 0 auto; width: 320px; }
        .totals td { border-bottom: none; padding: 4px 0; }
        .totals tr.grand td { border-top: 2px solid #111; font-weight: 700; font-size: 15px; padding-top: 8px; }
        .status { display: inline-block; border: 1px solid #111; padding: 1px 8px; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; }
        .foot { margin-top: 40px; color: #555; font-size: 11px; }
        @media print {
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; }
            .bar { display: none; }
            @page { margin: 18mm; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <a href="{{ route('invoices.show', $invoice) }}">Back to invoice</a>
        <button type="button" onclick="window.print()">Print</button>
    </div>
    <main class="sheet">
        <h1>{{ $invoice->status === 'draft' ? 'Draft invoice' : 'Invoice' }}</h1>
        <div class="meta">
            <strong>{{ $invoice->invoice_number }}</strong>
            @if($invoice->status !== 'open') <span class="status">{{ $invoice->status }}</span> @endif
        </div>

        @php $hasIssuer = ! empty($issuer['name']) || ! empty($issuer['address']) || ! empty($issuer['email']) || ! empty($issuer['tax_number']); @endphp
        <div class="parties" style="{{ $hasIssuer ? '' : 'grid-template-columns:1fr;' }}">
            @if($hasIssuer)
            <div>
                <div class="label">From</div>
                @if(! empty($issuer['name']))<div><strong>{{ $issuer['name'] }}</strong></div>@endif
                @foreach($issuer['address'] ?? [] as $line)<div>{{ $line }}</div>@endforeach
                @if(! empty($issuer['email']))<div>{{ $issuer['email'] }}</div>@endif
                @if(! empty($issuer['tax_number']))<div>Tax number: {{ $issuer['tax_number'] }}</div>@endif
            </div>
            @endif
            <div>
                <div class="label">Billed to</div>
                <div><strong>{{ $invoice->team->name }}</strong></div>
                <div class="meta" style="margin-top:12px;">
                    <div>Issued: {{ $invoice->issued_at?->format('j F Y') ?? 'not yet issued' }}</div>
                    <div>Due: {{ $invoice->due_date?->format('j F Y') ?? '—' }}</div>
                    @if($invoice->period_start && $invoice->period_end)
                        <div>Period: {{ $invoice->period_start->format('j M Y') }} to {{ $invoice->period_end->format('j M Y') }}</div>
                    @endif
                </div>
            </div>
        </div>

        <table>
            <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
            <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}@if($item->period)<div class="meta">{{ $item->period }}</div>@endif</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ (new \App\Support\Money((int) $item->unit_price_cents, $invoice->currency))->format() }}</td>
                    <td class="num">{{ (new \App\Support\Money((int) $item->amount_cents, $invoice->currency))->format() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr><td>Subtotal</td><td class="num">{{ $invoice->subtotalMoney()->format() }}</td></tr>
            @if($invoice->tax_cents > 0 || $invoice->tax_rate_bps > 0)
                <tr><td>Tax @if($invoice->tax_rate_bps > 0)({{ rtrim(rtrim(number_format($invoice->tax_rate_bps / 100, 2), '0'), '.') }}%)@endif</td><td class="num">{{ $invoice->taxMoney()->format() }}</td></tr>
            @endif
            <tr class="grand"><td>Total</td><td class="num">{{ $invoice->totalMoney()->format() }}</td></tr>
            @if($invoice->amount_paid_cents > 0)
                <tr><td>Paid</td><td class="num">{{ $invoice->amountPaidMoney()->format() }}</td></tr>
                <tr><td><strong>Balance due</strong></td><td class="num"><strong>{{ $invoice->balanceMoney()->format() }}</strong></td></tr>
            @endif
        </table>

        <p class="foot">Amounts are in {{ $invoice->currency }}.</p>
    </main>
</body>
</html>
