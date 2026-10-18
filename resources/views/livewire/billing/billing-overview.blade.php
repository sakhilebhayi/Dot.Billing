@php
    $sub = $this->subscription;
    $live = $sub?->isLive() === true;
    $manage = $this->canManage;
    $money = fn ($plan, $cycle) => $plan->isFree() ? 'Free' : $plan->priceFor($cycle)->format();
@endphp
<div class="dot-card" style="padding:1.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.1rem;">
        <h3 class="dot-h">Subscription</h3>
        @if($sub && $live)
            <span class="pill {{ $sub->status === 'past_due' ? 'overdue' : ($sub->status === 'active' ? 'paid' : 'grace') }}">{{ str_replace('_', ' ', ucfirst($sub->status)) }}</span>
        @elseif($sub)
            <span class="pill void">Canceled</span>
        @endif
    </div>

    @error('billing')
        <div class="field-error" role="alert" style="margin:0 0 12px;font-size:12px;">{{ $message }}</div>
    @enderror

    @if($sub && $live)
        <div style="display:flex;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;">
            <div>
                <div style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:700;color:#818cf8;">{{ $sub->plan->name }}</div>
                <div class="dot-muted" style="margin-top:4px;">{{ ucfirst($sub->billing_cycle) }} billing</div>

                @if($sub->status === 'trialing')
                    <div style="margin-top:10px;font-size:12px;color:#f59e0b;">Trial ends on {{ $sub->trial_ends_at->format('j M Y') }}.</div>
                    <div class="dot-muted" style="margin-top:2px;">You are not charged automatically. An invoice is raised when the trial ends.</div>
                @elseif($sub->cancel_at_period_end)
                    <div style="margin-top:10px;font-size:12px;color:#f59e0b;">Your subscription ends on {{ $sub->current_period_end->format('j M Y') }}.</div>
                    <div class="dot-muted" style="margin-top:2px;">You keep access until then. No further invoice will be raised.</div>
                @else
                    <div style="margin-top:10px;font-size:12px;color:#d4d4d8;">Renews on {{ $sub->current_period_end->format('j M Y') }}.</div>
                @endif

                @if($sub->status === 'past_due' && $this->overdueInvoice)
                    <div style="margin-top:10px;font-size:12px;color:#f87171;">
                        Payment overdue:
                        <a href="{{ route('invoices.show', $this->overdueInvoice) }}" style="color:#f87171;font-family:'JetBrains Mono',monospace;">{{ $this->overdueInvoice->invoice_number }}</a>
                        ({{ $this->overdueInvoice->balanceMoney()->format() }}, due {{ $this->overdueInvoice->due_date->format('j M Y') }}).
                    </div>
                @endif

                @if($sub->pendingPlan)
                    <div style="margin-top:10px;font-size:12px;color:#a5b4fc;">
                        Switching to {{ $sub->pendingPlan->name }} ({{ $sub->pending_billing_cycle }}) on {{ $sub->current_period_end->format('j M Y') }}.
                        @if($manage)
                            <button type="button" wire:click="keepCurrentPlan" class="dot-btn dot-btn-ghost" style="padding:2px 8px;font-size:11px;margin-left:6px;">Keep current plan</button>
                        @endif
                    </div>
                @endif
            </div>

            @if($this->nextInvoice)
                <div style="text-align:right;">
                    <div class="dot-label">Oldest unpaid invoice</div>
                    <div class="num" style="font-size:1.25rem;font-weight:600;color:#f4f4f5;margin-top:2px;">{{ $this->nextInvoice->balanceMoney()->format() }}</div>
                    <div class="dot-muted">Due {{ $this->nextInvoice->due_date?->format('j M Y') ?? 'on finalizing' }}</div>
                </div>
            @endif
        </div>

        @if($manage)
            <div class="btn-row" style="margin-top:1.1rem;">
                @if($sub->cancel_at_period_end)
                    <button type="button" wire:click="resume" class="dot-btn dot-btn-primary">Resume subscription</button>
                @else
                    <button type="button" wire:click="cancelAtPeriodEnd" wire:confirm="Cancel at the end of the current period? You keep access until {{ $sub->current_period_end->format('j M Y') }}." class="dot-btn dot-btn-danger">Cancel subscription</button>
                @endif
            </div>
        @endif
    @elseif($sub)
        <div style="font-size:13px;color:#d4d4d8;">Canceled on {{ ($sub->canceled_at ?? $sub->ended_at ?? $sub->updated_at)->format('j M Y') }}.</div>
        <div class="dot-muted" style="margin-top:2px;">Previous plan: {{ $sub->plan->name }}.</div>
    @endif

    @if($manage && (! $live || ! $sub->cancel_at_period_end))
        <div style="margin-top:{{ $sub && $live ? '1.5rem' : '1rem' }};">
            <div class="dot-label" style="margin-bottom:8px;">{{ $live ? 'Change plan' : 'Choose a plan' }}</div>
            @if($this->plans->isEmpty())
                <p class="dot-muted" style="margin:0;">No plans are on offer yet. A platform operator publishes them in the plan catalogue.</p>
            @else
                <div class="table-scroll">
                    <table class="dot-table">
                        <thead><tr><th>Plan</th><th>Limits</th><th class="num">Monthly</th><th class="num">Annual</th><th></th></tr></thead>
                        <tbody>
                        @foreach($this->plans as $plan)
                            @php $current = $live && $plan->id === $sub->plan_id; @endphp
                            <tr wire:key="plan-{{ $plan->id }}">
                                <td>
                                    <div style="font-weight:600;color:#f4f4f5;">{{ $plan->name }}@if($plan->trial_days > 0 && ! $live) <span class="pill grace" style="margin-left:6px;">{{ $plan->trial_days }}-day trial</span>@endif</div>
                                    @if($plan->description)<div class="dot-muted">{{ $plan->description }}</div>@endif
                                </td>
                                <td class="dot-muted">
                                    {{ $plan->seat_limit ? $plan->seat_limit.' '.\Illuminate\Support\Str::plural('seat', $plan->seat_limit) : 'Unlimited seats' }} · {{ $plan->storage_gb ? $plan->storage_gb.' GB' : 'Unlimited storage' }}
                                </td>
                                <td class="num">{{ $money($plan, 'monthly') }}</td>
                                <td class="num">{{ $money($plan, 'annual') }}</td>
                                <td>
                                    <div class="btn-row" style="justify-content:flex-end;">
                                        @foreach(['monthly', 'annual'] as $cycle)
                                            @if($live && $current && $cycle === $sub->billing_cycle)
                                                <span class="dot-muted">Current</span>
                                            @else
                                                <button type="button" class="dot-btn dot-btn-ghost" style="padding:4px 10px;font-size:12px;"
                                                    wire:click="{{ $live ? 'changePlan' : 'subscribe' }}({{ $plan->id }}, '{{ $cycle }}')"
                                                    wire:confirm="{{ $live ? 'Switch to '.$plan->name.' ('.$cycle.') at your next renewal? Nothing changes until then.' : 'Start '.$plan->name.' ('.$cycle.')?' }}">
                                                    {{ $live ? 'Switch' : 'Choose' }} {{ $cycle }}
                                                </button>
                                            @endif
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @elseif(! $manage)
        <p class="dot-muted" style="margin:{{ $sub ? '1rem' : '0' }} 0 0;">Only team owners and admins can change billing.</p>
    @endif

    @if($notice)
        <div class="notice" role="status">{{ $notice }}</div>
    @endif
</div>
