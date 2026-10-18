@php
    $reasons = [
        'amount_mismatch' => 'Amount mismatch',
        'currency_mismatch' => 'Currency mismatch',
        'reference_mismatch' => 'Reference mismatch',
        'unknown_reference' => 'Unknown reference',
        'status_not_success' => 'Provider did not report success',
        'unreadable_payload' => 'Unreadable payload',
        'overpayment' => 'Overpayment',
        'invoice_not_payable' => 'Invoice already settled',
        'currency_mismatch_invoice' => 'Currency differs from invoice',
        'payment_conflict' => 'Same id, different details',
        'refund_not_modelled' => 'Refund: not applied automatically',
    ];
    $statusLabels = ['applied' => 'Applied', 'unmatched' => 'Unmatched', 'needs_review' => 'Needs review'];
@endphp
<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 1.5rem;letter-spacing:-0.01em;">Payment events</h1>

    @if($attentionCount > 0)
        <div class="state-line attn" role="status" style="margin-bottom:1rem;"><span class="dot" aria-hidden="true"></span><span>{{ $attentionCount }} payment {{ \Illuminate\Support\Str::plural('event', $attentionCount) }} {{ $attentionCount === 1 ? 'needs' : 'need' }} an operator. Nothing was applied to an invoice for these; look into each one with the provider.</span></div>
    @else
        <div class="state-line ok" role="status" style="margin-bottom:1rem;"><span class="dot" aria-hidden="true"></span><span>No payment events need an operator.</span></div>
    @endif

    <div class="btn-row" style="margin-bottom:1rem;" role="navigation" aria-label="Filter payment events">
        <a href="{{ route('operator.payment-events.index') }}" class="dot-btn {{ $attentionOnly ? 'dot-btn-ghost' : 'dot-btn-primary' }}" style="padding:4px 12px;font-size:12px;" @if(! $attentionOnly) aria-current="page" @endif>All events</a>
        <a href="{{ route('operator.payment-events.index', ['show' => 'attention']) }}" class="dot-btn {{ $attentionOnly ? 'dot-btn-primary' : 'dot-btn-ghost' }}" style="padding:4px 12px;font-size:12px;" @if($attentionOnly) aria-current="page" @endif>Needs attention</a>
    </div>

    <div class="dot-card" style="padding:1.25rem 1.5rem;">
        @if($events->isEmpty())
            <p class="dot-muted" style="margin:0;">{{ $attentionOnly ? 'No payment events need attention.' : 'No payment events have been recorded yet.' }}</p>
        @else
            <div class="table-scroll">
                <table class="dot-table">
                    <thead>
                        <tr><th>Received</th><th>Provider</th><th>Type</th><th>Reference</th><th>Invoice</th><th>Status</th><th class="num">Amount</th></tr>
                    </thead>
                    <tbody>
                    @foreach($events as $event)
                        <tr class="{{ $event->needsAttention() ? 'needs-attention' : '' }}">
                            <td class="dot-muted">{{ $event->received_at->format('j M Y H:i') }}</td>
                            <td>{{ ucfirst($event->provider ?? 'manual') }}</td>
                            <td>{{ ucfirst($event->type) }}</td>
                            <td style="font-family:'JetBrains Mono',monospace;font-size:11px;word-break:break-all;max-width:18rem;">{{ $event->payload['reference'] ?? $event->external_id ?? '—' }}</td>
                            <td>
                                @if($event->invoice)
                                    <span style="font-family:'JetBrains Mono',monospace;">{{ $event->invoice->invoice_number }}</span>
                                    <div class="dot-muted">{{ $event->team?->name }}</div>
                                @else
                                    <span class="dot-muted">No invoice matched</span>
                                @endif
                            </td>
                            <td>
                                <span class="pill {{ $event->status === 'applied' ? 'paid' : 'overdue' }}">{{ $statusLabels[$event->status] ?? ucfirst(str_replace('_', ' ', $event->status)) }}</span>
                                @if($event->reason)
                                    <div class="dot-muted" style="margin-top:4px;">{{ $reasons[$event->reason] ?? ucfirst(str_replace('_', ' ', $event->reason)) }}</div>
                                @endif
                            </td>
                            <td class="num">{{ $event->amountMoney()->format() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top:1rem;">{{ $events->links() }}</div>
        @endif
    </div>
</div>
</x-app-layout>
