@php
    $sub = $this->subscription;
    $plan = $sub?->plan;
    $unpaid = $this->unpaidPurchase;
    $unpaidInvoice = $unpaid?->invoice;
    $price = $plan?->extraSeatPriceFor($sub->billing_cycle);
    $sells = $plan?->sellsExtraSeats($sub->billing_cycle) === true;
    $linked = $this->linkedToInfoDot;
    $extra = (int) ($sub?->extra_seats ?? 0);
    $included = $plan?->seat_limit;
    $periodEnd = $sub?->current_period_end;
    $reducing = $sub && $sub->pending_extra_seats !== null && (int) $sub->pending_extra_seats < $extra;
    $paystack = app(\App\Services\Payments\Paystack\PaystackConfig::class);
    $seatWord = fn (int $n) => $n.' '.\Illuminate\Support\Str::plural('seat', $n);
@endphp
<div>
@if(! $sub)
    <div class="dot-card" style="padding:1.5rem;">
        <h3 class="dot-h">No running subscription</h3>
        <p class="dot-muted" style="margin:6px 0 1rem;max-width:40rem;">Extra seats are added to a plan, and this team does not have one running. Choose a plan first, then come back here.</p>
        <a href="{{ route('dashboard') }}" class="dot-btn dot-btn-ghost">Go to the dashboard</a>
    </div>
@else
    <div class="dot-card" style="padding:0.5rem 1.5rem;">
        <div class="readout">
            <div class="k">Plan</div>
            <div class="v">{{ $plan->name }} <span style="font-family:'Inter',sans-serif;color:#a1a1aa;">{{ ucfirst($sub->billing_cycle) }} billing</span></div>
            <div class="k">Included seats</div>
            <div class="v">{{ $included === null ? 'Unlimited' : $seatWord((int) $included) }}</div>
            <div class="k">Extra seats you pay for</div>
            <div class="v">{{ $extra }}@if($included !== null) <span style="font-family:'Inter',sans-serif;color:#a1a1aa;">{{ $seatWord((int) $included + $extra) }} in total</span>@endif</div>
            <div class="k">Price per extra seat</div>
            <div class="v">
                @if($sells)
                    <span>{{ $price->format() }}</span><span style="font-family:'Inter',sans-serif;color:#a1a1aa;">per seat, per {{ $sub->billing_cycle === 'annual' ? 'year' : 'month' }}</span>
                @else
                    <span class="none">Not available on this plan</span>
                @endif
            </div>
            <div class="k">Current period ends</div>
            <div class="v">{{ $periodEnd?->format('j M Y') ?? 'Not set' }}</div>
        </div>
    </div>

    @if(! $linked)
        <div class="state-line attn" role="status" style="margin-top:1.25rem;"><span class="dot" aria-hidden="true"></span><span>This team is not linked to an InfoDot company, so InfoDot would not see any seats bought here and you cannot buy seats for it. Open Dot.Billing from your company in InfoDot.</span></div>
    @elseif(! $sells)
        <div class="state-line attn" role="status" style="margin-top:1.25rem;"><span class="dot" aria-hidden="true"></span><span>Extra seats are not available on the {{ $plan->name }} plan, so you cannot add seats here.@if($extra > 0) You can still reduce the extra seats you already have.@endif</span></div>
    @endif

    @if($unpaid && $unpaidInvoice)
        <section class="dot-card" style="padding:1.5rem;margin-top:1.25rem;" aria-labelledby="unpaid-seats-title">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <h3 class="dot-h" id="unpaid-seats-title">Seat purchase waiting for payment</h3>
                <span class="pill open">Unpaid</span>
                @if($paystack->isTestMode())<span class="pill test">TEST MODE</span>@endif
            </div>
            <p class="dot-muted" style="margin:6px 0 1rem;max-width:42rem;">
                You asked for {{ $seatWord($unpaid->to_extra_seats - $unpaid->from_extra_seats) }} more
                (extra seats {{ $unpaid->from_extra_seats }} to {{ $unpaid->to_extra_seats }}) on invoice
                <a href="{{ route('invoices.show', $unpaidInvoice) }}" class="row-link" style="color:#a5b4fc;font-family:'JetBrains Mono',monospace;">{{ $unpaidInvoice->invoice_number }}</a>.
                The new seats count only once the invoice is paid in full. Until then your extra seats stay at {{ $extra }}.
            </p>
            <div class="btn-row">
                @if(in_array($unpaidInvoice->status, ['open'], true) && $unpaidInvoice->balanceMoney()->isPositive() && $paystack->canTakePayments())
                    <form method="POST" action="{{ route('invoices.pay', $unpaidInvoice) }}">
                        @csrf
                        <button type="submit" class="dot-btn dot-btn-primary">
                            <span class="material-symbols-rounded" style="font-size:16px;" aria-hidden="true">lock</span>
                            Pay now <span class="num" style="margin-left:4px;">{{ $unpaidInvoice->balanceMoney()->format() }}</span>
                        </button>
                    </form>
                @endif
                <a href="{{ route('invoices.show', $unpaidInvoice) }}" class="dot-btn dot-btn-ghost">View invoice</a>
            </div>
            @if($unpaidInvoice->status === 'draft')
                <p class="dot-muted" style="margin:0.9rem 0 0;">This invoice has not been finalized yet, so it cannot be paid. It will be payable once it is issued.</p>
            @elseif(! $paystack->canTakePayments())
                <div class="state-line attn" role="status" style="margin-top:0.9rem;"><span class="dot" aria-hidden="true"></span><span>{{ $paystack->notice() }}</span></div>
            @endif
        </section>
    @endif

    @if($reducing)
        <div class="state-line attn" role="status" style="margin-top:1.25rem;"><span class="dot" aria-hidden="true"></span>
            <span>Your extra seats drop from {{ $extra }} to {{ (int) $sub->pending_extra_seats }} on {{ $periodEnd?->format('j M Y') }}. You keep all {{ $extra }} until then. Nothing is refunded for the current period.</span>
        </div>
    @endif

    @if($linked && ($sells || $extra > 0))
        <section class="dot-card" style="padding:1.5rem;margin-top:1.25rem;" aria-labelledby="seats-form-title">
            <h3 class="dot-h" id="seats-form-title">Change extra seats</h3>
            <p class="dot-muted" style="margin:6px 0 1rem;max-width:42rem;">
                Enter the total number of extra seats you want, not how many to add. Adding is charged now, pro rata for the rest of this period{{ $periodEnd ? ' (until '.$periodEnd->format('j M Y').')' : '' }}, and the seats count only after the invoice is paid. Reducing takes effect at the end of the period, with no refund or credit.
            </p>

            @error('seats')
                <div class="state-line bad" role="alert" style="margin-bottom:1rem;"><span class="dot" aria-hidden="true"></span><span>{{ $message }}</span></div>
            @enderror

            <form wire:submit="submit">
                <div style="max-width:14rem;">
                    <label class="dot-label" for="target-extra-seats" style="display:block;margin-bottom:5px;">Extra seats in total</label>
                    <input id="target-extra-seats" type="number" inputmode="numeric" min="0" step="1" class="dot-input" wire:model.live.debounce.400ms="targetExtraSeats" aria-describedby="target-extra-seats-help" @error('targetExtraSeats') aria-invalid="true" @enderror>
                    @error('targetExtraSeats')<div class="field-error" role="alert">{{ $message }}</div>@enderror
                    <div id="target-extra-seats-help" class="dot-muted" style="margin-top:4px;">You have {{ $extra }} now.</div>
                </div>
                <div class="btn-row" style="margin-top:1rem;">
                    <button type="submit" class="dot-btn dot-btn-primary" wire:loading.attr="disabled" wire:target="submit">
                        <span wire:loading.remove wire:target="submit">Confirm change</span>
                        <span wire:loading wire:target="submit">Working...</span>
                    </button>
                </div>
            </form>

            @if($result)
                <div class="state-line ok" role="status" style="margin-top:1.1rem;"><span class="dot" aria-hidden="true"></span>
                    <span>
                        @if($result['replayed'])That request was already submitted, so nothing new was done. @endif
                        @if($result['kind'] === 'add' && $unpaid && $unpaidInvoice)
                            Invoice {{ $unpaidInvoice->invoice_number }} for {{ $unpaidInvoice->totalMoney()->format() }} was raised for {{ $seatWord($result['to'] - $result['from']) }} more, pro rata for the rest of this period. The seats count only after it is paid.
                        @elseif($result['kind'] === 'add')
                            The purchase for extra seats {{ $result['from'] }} to {{ $result['to'] }} was recorded. The seats count only after its invoice is paid.
                        @elseif($result['kind'] === 'reduce')
                            Your extra seats will drop from {{ $result['from'] }} to {{ $result['to'] }} on {{ \Illuminate\Support\Carbon::parse($result['effective'])->format('j M Y') }}. You keep {{ $seatWord($result['from']) }} until then, and nothing is refunded.
                        @else
                            You already have {{ $result['to'] }} extra {{ \Illuminate\Support\Str::plural('seat', $result['to']) }}, so nothing changed. Any reduction that was scheduled has been cancelled.
                        @endif
                    </span>
                </div>
            @endif
        </section>
    @endif
@endif
</div>
