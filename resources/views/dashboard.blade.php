<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">Billing Dashboard</h1>
            <p style="font-size:0.78rem;color:#71717a;margin:0;">{{ now()->format('l, F j, Y') }}</p>
        </div>
    </div>

    <div class="state-line {{ $attention['level'] }}" role="status"><span class="dot" aria-hidden="true"></span><span>{{ $attention['text'] }}</span></div>

    <div class="dot-card" style="padding:0.5rem 1.5rem;margin-top:1.25rem;">
        <div class="readout">
            <div class="k">Plan</div>
            <div class="v">{{ $planName }}@if($subscription) <span style="font-family:'Inter',sans-serif;color:#a1a1aa;">{{ str_replace('_', ' ', $subscription->status) }}</span>@endif</div>
            <div class="k">Open invoices</div>
            <div class="v">{{ $openInvoices }}@if($overdueInvoices > 0) <span style="color:#f87171;">{{ $overdueInvoices }} overdue</span>@endif</div>
            <div class="k">Outstanding</div>
            <div class="v">
                @forelse($outstandingByCurrency as $money)<span>{{ $money->format() }}</span>@empty<span class="none">Nothing owed</span>@endforelse
            </div>
            <div class="k">Paid this year</div>
            <div class="v">
                @forelse($paidYtdByCurrency as $money)<span>{{ $money->format() }}</span>@empty<span class="none">No payments yet this year</span>@endforelse
            </div>
            <div class="k">Active alerts</div>
            <div class="v">{{ $activeAlerts }}</div>
        </div>
    </div>

    <div style="margin-top:1.25rem;">
        <livewire:billing.billing-overview />
    </div>
    <div style="margin-top:1.25rem;">
        <livewire:billing.payment-reliability />
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-top:1.25rem;">
        <livewire:billing.invoice-table />
        <livewire:billing.usage-dashboard />
    </div>
</div>
</x-app-layout>
