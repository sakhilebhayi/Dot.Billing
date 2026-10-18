@php
    $paystack = app(\App\Services\Payments\Paystack\PaystackConfig::class);
    $owing = $invoice->balanceMoney();
    $waiting = \App\Models\PaymentAttempt::withoutGlobalScope('team')
        ->where('invoice_id', $invoice->id)->where('status', 'initiated')->where('expires_at', '>', now())
        ->latest('id')->first();
@endphp
<section class="dot-card" style="padding:1.5rem;margin-top:1.25rem;" aria-labelledby="pay-online-title">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <h3 class="dot-h" id="pay-online-title">Pay online</h3>
        @if($paystack->isTestMode())
            <span class="pill test">TEST MODE</span>
        @endif
    </div>

    @if($paystack->canTakePayments())
        <p class="dot-muted" style="margin:6px 0 1rem;max-width:40rem;">
            Pay the {{ $invoice->amountPaidMoney()->isPositive() ? 'remaining balance' : 'full balance' }} by card or bank through Paystack. You are sent to Paystack's page and brought back here; the invoice closes only when Paystack confirms the payment.
            @if($paystack->isTestMode())
                This environment is in test mode: use Paystack's test cards, no real money moves.
            @endif
        </p>
        @if($waiting)
            <p class="notice" style="margin:0 0 0.9rem;">A payment started {{ $waiting->created_at->diffForHumans() }} is waiting. Pay now reopens it instead of starting another.</p>
        @endif
        <form method="POST" action="{{ route('invoices.pay', $invoice) }}" class="btn-row">
            @csrf
            <button type="submit" class="dot-btn dot-btn-primary">
                <span class="material-symbols-rounded" style="font-size:16px;" aria-hidden="true">lock</span>
                Pay now <span class="num" style="margin-left:4px;">{{ $owing->format() }}</span>
            </button>
        </form>
    @else
        <div class="state-line attn" role="status" style="margin-top:0.9rem;"><span class="dot" aria-hidden="true"></span><span>{{ $paystack->notice() }}</span></div>
    @endif
</section>
