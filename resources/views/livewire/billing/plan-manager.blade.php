<div class="r-stack" style="display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:1.25rem;align-items:start;">
    <div class="dot-card" style="padding:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <h3 class="dot-h">Plans</h3>
            <button type="button" wire:click="newPlan" class="dot-btn dot-btn-ghost" style="padding:4px 10px;font-size:12px;">New plan</button>
        </div>

        @if($this->plans->isEmpty())
            <p class="dot-muted" style="margin:0;">No plans yet. Create the first one; until then teams have nothing to choose.</p>
        @else
            <div class="table-scroll">
                <table class="dot-table">
                    <thead><tr><th>Plan</th><th class="num">Monthly</th><th class="num">Annual</th><th>Limits</th><th class="num">Teams</th><th></th></tr></thead>
                    <tbody>
                    @foreach($this->plans as $plan)
                        <tr wire:key="plan-{{ $plan->id }}" style="{{ $plan->is_active ? '' : 'opacity:.65;' }}">
                            <td>
                                <div style="font-weight:600;color:#f4f4f5;">{{ $plan->name }} @unless($plan->is_active)<span class="pill void" style="text-decoration:none;">Archived</span>@endunless</div>
                                <div class="dot-muted" style="font-family:'JetBrains Mono',monospace;">{{ $plan->slug }}</div>
                            </td>
                            <td class="num">{{ $plan->isFree() ? 'Free' : $plan->priceFor('monthly')->format() }}</td>
                            <td class="num">{{ (int) $plan->price_annual_cents === 0 ? 'Free' : $plan->priceFor('annual')->format() }}</td>
                            <td class="dot-muted">{{ $plan->seat_limit ? $plan->seat_limit.' '.\Illuminate\Support\Str::plural('seat', $plan->seat_limit) : 'Unlimited seats' }} · {{ $plan->storage_gb ? $plan->storage_gb.' GB' : 'Unlimited' }}@if($plan->trial_days) · {{ $plan->trial_days }}d trial @endif</td>
                            <td class="num">{{ $plan->live_subscribers }}</td>
                            <td>
                                <div class="btn-row" style="justify-content:flex-end;flex-wrap:nowrap;">
                                    <button type="button" class="dot-btn dot-btn-ghost" style="padding:3px 9px;font-size:11px;" wire:click="edit({{ $plan->id }})">Edit</button>
                                    @if($plan->is_active)
                                        <button type="button" class="dot-btn dot-btn-ghost" style="padding:3px 9px;font-size:11px;" wire:click="archive({{ $plan->id }})" wire:confirm="Archive {{ $plan->name }}? Teams already on it keep being billed; nobody new can choose it.">Archive</button>
                                    @else
                                        <button type="button" class="dot-btn dot-btn-ghost" style="padding:3px 9px;font-size:11px;" wire:click="restore({{ $plan->id }})">Restore</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($notice)<div class="notice" role="status">{{ $notice }}</div>@endif
    </div>

    <form wire:submit="save" class="dot-card" style="padding:1.5rem;">
        <h3 class="dot-h" style="margin-bottom:1rem;">{{ $editingId ? 'Edit plan' : 'New plan' }}</h3>
        <div class="form-grid">
            <div class="wide">
                <label class="dot-label" for="pm-name">Name</label>
                <input id="pm-name" type="text" wire:model="name" class="dot-input" maxlength="80">
                @error('name')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="dot-label" for="pm-slug">Slug</label>
                <input id="pm-slug" type="text" wire:model="slug" class="dot-input" maxlength="80" placeholder="auto from name">
                @error('slug')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="dot-label" for="pm-currency">Currency</label>
                <input id="pm-currency" type="text" wire:model="currency" class="dot-input" maxlength="3" style="text-transform:uppercase;">
                @error('currency')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="dot-label" for="pm-monthly">Monthly price</label>
                <input id="pm-monthly" type="text" inputmode="decimal" wire:model="priceMonthly" class="dot-input" placeholder="499.00">
                @error('priceMonthly')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="dot-label" for="pm-annual">Annual price</label>
                <input id="pm-annual" type="text" inputmode="decimal" wire:model="priceAnnual" class="dot-input" placeholder="4990.00">
                @error('priceAnnual')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="dot-label" for="pm-seats">Seat limit</label>
                <input id="pm-seats" type="text" inputmode="numeric" wire:model="seatLimit" class="dot-input" placeholder="blank = unlimited">
                @error('seatLimit')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="dot-label" for="pm-xseat-m">Extra seat, monthly</label>
                <input id="pm-xseat-m" type="text" inputmode="decimal" wire:model="extraSeatMonthly" class="dot-input" placeholder="blank = not sold">
                @error('extraSeatMonthly')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="dot-label" for="pm-xseat-a">Extra seat, annual</label>
                <input id="pm-xseat-a" type="text" inputmode="decimal" wire:model="extraSeatAnnual" class="dot-input" placeholder="blank = not sold">
                @error('extraSeatAnnual')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="dot-label" for="pm-storage">Storage (GB)</label>
                <input id="pm-storage" type="text" inputmode="numeric" wire:model="storageGb" class="dot-input" placeholder="blank = unlimited">
                @error('storageGb')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="dot-label" for="pm-trial">Trial days</label>
                <input id="pm-trial" type="text" inputmode="numeric" wire:model="trialDays" class="dot-input">
                @error('trialDays')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <div class="wide">
                <label class="dot-label" for="pm-desc">Description</label>
                <input id="pm-desc" type="text" wire:model="description" class="dot-input" maxlength="300">
                @error('description')<div class="field-error" role="alert">{{ $message }}</div>@enderror
            </div>
            <div class="wide">
                <label class="dot-label" for="pm-features">Features (one per line)</label>
                <textarea id="pm-features" wire:model="features" rows="4" class="dot-input" style="font-family:'JetBrains Mono',monospace;" placeholder="api_access&#10;priority_support"></textarea>
                <div class="dot-muted" style="margin-top:4px;">These identifiers are what other Dot apps receive from the entitlements API.</div>
            </div>
        </div>
        <div class="btn-row" style="margin-top:1.1rem;">
            <button type="submit" class="dot-btn dot-btn-primary">{{ $editingId ? 'Save plan' : 'Create plan' }}</button>
            @if($editingId)<button type="button" wire:click="newPlan" class="dot-btn dot-btn-ghost">Cancel edit</button>@endif
        </div>
    </form>
</div>
