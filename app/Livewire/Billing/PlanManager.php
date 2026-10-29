<?php

namespace App\Livewire\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Operator-only plan catalogue: create, edit, archive, restore. There is deliberately no delete:
 * subscriptions and invoice history point at plans, and a plan someone is billed on must stay.
 */
class PlanManager extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public string $currency = 'ZAR';

    public string $priceMonthly = '';

    public string $priceAnnual = '';

    public string $seatLimit = '';

    /** Price of one extra seat beyond the seat limit. Blank = this plan sells no extra seats (never defaulted). */
    public string $extraSeatMonthly = '';

    public string $extraSeatAnnual = '';

    public string $storageGb = '';

    public string $trialDays = '0';

    public string $features = '';

    public ?string $notice = null;

    public function mount(): void
    {
        Gate::authorize('manage-plans');
    }

    /** @return Collection<int, BillingPlan> */
    #[Computed]
    public function plans(): Collection
    {
        return BillingPlan::query()
            ->withCount(['subscriptions as live_subscribers' => fn ($q) => $q->withoutGlobalScope('team')->whereIn('status', BillingSubscription::LIVE_STATUSES)])
            ->orderByDesc('is_active')->orderBy('price_monthly_cents')->orderBy('name')
            ->get();
    }

    public function newPlan(): void
    {
        Gate::authorize('manage-plans');
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-plans');
        $plan = BillingPlan::findOrFail($id);

        $this->resetValidation();
        $this->editingId = $plan->id;
        $this->name = $plan->name;
        $this->slug = $plan->slug;
        $this->description = (string) $plan->description;
        $this->currency = $plan->currency;
        $this->priceMonthly = Money::centsToDecimal((int) $plan->price_monthly_cents);
        $this->priceAnnual = Money::centsToDecimal((int) $plan->price_annual_cents);
        $this->seatLimit = $plan->seat_limit === null ? '' : (string) $plan->seat_limit;
        $this->extraSeatMonthly = $plan->extra_seat_price_monthly_cents === null ? '' : Money::centsToDecimal((int) $plan->extra_seat_price_monthly_cents);
        $this->extraSeatAnnual = $plan->extra_seat_price_annual_cents === null ? '' : Money::centsToDecimal((int) $plan->extra_seat_price_annual_cents);
        $this->storageGb = $plan->storage_gb === null ? '' : (string) $plan->storage_gb;
        $this->trialDays = (string) $plan->trial_days;
        $this->features = implode("\n", $plan->features ?? []);
        $this->notice = null;
    }

    public function save(): void
    {
        Gate::authorize('manage-plans');

        $this->currency = strtoupper(trim($this->currency));
        if ($this->slug === '' && $this->editingId === null) {
            $this->slug = Str::slug($this->name);
        }

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('billing_plans', 'slug')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:300'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'priceMonthly' => ['required', $this->priceRule()],
            'priceAnnual' => ['required', $this->priceRule()],
            'seatLimit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'extraSeatMonthly' => ['nullable', $this->seatPriceRule()],
            'extraSeatAnnual' => ['nullable', $this->seatPriceRule()],
            'storageGb' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'trialDays' => ['required', 'integer', 'min:0', 'max:365'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and single dashes.',
            'currency.regex' => 'Use a three-letter currency code such as ZAR.',
        ]);

        $monthly = Money::parse($this->priceMonthly, $this->currency, allowZero: true);
        $annual = Money::parse($this->priceAnnual, $this->currency, allowZero: true);

        if ($this->editingId !== null) {
            $existing = BillingPlan::findOrFail($this->editingId);
            $hasSubscribers = $existing->subscriptions()->withoutGlobalScope('team')->exists();

            if ($hasSubscribers && $existing->currency !== $this->currency) {
                $this->addError('currency', 'Teams are already billed on this plan, so its currency cannot change. Create a new plan instead.');

                return;
            }
        }

        $attributes = [
            'name' => trim($this->name),
            'slug' => $this->slug,
            'description' => trim($this->description) ?: null,
            'currency' => $this->currency,
            'price_monthly_cents' => $monthly->cents,
            'price_annual_cents' => $annual->cents,
            'seat_limit' => $this->seatLimit === '' ? null : (int) $this->seatLimit,
            'extra_seat_price_monthly_cents' => trim($this->extraSeatMonthly) === '' ? null : Money::parse($this->extraSeatMonthly, $this->currency)->cents,
            'extra_seat_price_annual_cents' => trim($this->extraSeatAnnual) === '' ? null : Money::parse($this->extraSeatAnnual, $this->currency)->cents,
            'storage_gb' => $this->storageGb === '' ? null : (int) $this->storageGb,
            'trial_days' => (int) $this->trialDays,
            'features' => array_values(array_filter(array_map('trim', preg_split('/\R/', $this->features) ?: []))),
        ];

        if ($this->editingId !== null) {
            BillingPlan::findOrFail($this->editingId)->update($attributes);
            $this->notice = 'Plan saved.';
        } else {
            BillingPlan::create($attributes + ['is_active' => true]);
            $this->notice = 'Plan created.';
        }

        $this->resetForm(keepNotice: true);
        unset($this->plans);
    }

    public function archive(int $id): void
    {
        Gate::authorize('manage-plans');
        BillingPlan::findOrFail($id)->update(['is_active' => false]);
        $this->notice = 'Plan archived. Teams already on it keep being billed; nobody new can choose it.';
        unset($this->plans);
    }

    public function restore(int $id): void
    {
        Gate::authorize('manage-plans');
        BillingPlan::findOrFail($id)->update(['is_active' => true]);
        $this->notice = 'Plan restored.';
        unset($this->plans);
    }

    /** Strict amounts only: the same parser the rest of billing uses, so "12,50" is an error, not 1250. */
    private function priceRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            try {
                Money::parse((string) $value, 'ZAR', allowZero: true);
            } catch (BillingRuleViolation $e) {
                $fail($e->getMessage());
            }
        };
    }

    /** An extra-seat price is a real amount above zero, or blank for "not sold". */
    private function seatPriceRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (trim((string) $value) === '') {
                return;
            }

            try {
                Money::parse((string) $value, 'ZAR');
            } catch (BillingRuleViolation $e) {
                $fail($e->getMessage());
            }
        };
    }

    private function resetForm(bool $keepNotice = false): void
    {
        $notice = $keepNotice ? $this->notice : null;
        $this->reset(['editingId', 'name', 'slug', 'description', 'priceMonthly', 'priceAnnual', 'seatLimit', 'extraSeatMonthly', 'extraSeatAnnual', 'storageGb', 'features']);
        $this->currency = 'ZAR';
        $this->trialDays = '0';
        $this->resetValidation();
        $this->notice = $notice;
    }

    public function render()
    {
        return view('livewire.billing.plan-manager');
    }
}
