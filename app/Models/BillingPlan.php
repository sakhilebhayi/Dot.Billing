<?php

namespace App\Models;

use App\Models\Concerns\SyncsLegacyDecimals;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingPlan extends Model
{
    use SyncsLegacyDecimals;

    protected $table = 'billing_plans';

    /** @var array<string, string> */
    protected array $legacyDecimals = ['price_monthly' => 'price_monthly_cents', 'price_annual' => 'price_annual_cents'];

    /** Mirror the column defaults so a freshly created instance is correct without a refresh. */
    protected $attributes = [
        'is_active' => true,
        'trial_days' => 0,
        'currency' => 'ZAR',
    ];

    protected $fillable = [
        'name', 'slug', 'description', 'price_monthly', 'price_annual', 'price_monthly_cents', 'price_annual_cents',
        'currency', 'seat_limit', 'storage_gb', 'features', 'is_active', 'trial_days',
        'extra_seat_price_monthly_cents', 'extra_seat_price_annual_cents',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'trial_days' => 'integer',
        'price_monthly' => 'decimal:2',
        'price_annual' => 'decimal:2',
        'price_monthly_cents' => 'integer',
        'price_annual_cents' => 'integer',
        'extra_seat_price_monthly_cents' => 'integer',
        'extra_seat_price_annual_cents' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(BillingSubscription::class, 'plan_id');
    }

    public function priceFor(string $cycle): Money
    {
        return new Money((int) ($cycle === 'annual' ? $this->price_annual_cents : $this->price_monthly_cents), $this->currency);
    }

    /**
     * What one extra seat costs for a billing cycle, or null when this plan sells no extra seats. There is
     * deliberately no default: an unset price means nothing can be charged for seats on this plan.
     */
    public function extraSeatPriceFor(string $cycle): ?Money
    {
        $cents = $cycle === 'annual' ? $this->extra_seat_price_annual_cents : $this->extra_seat_price_monthly_cents;

        return $cents === null ? null : new Money((int) $cents, $this->currency);
    }

    /** True only when a positive extra-seat price is set for the cycle. */
    public function sellsExtraSeats(string $cycle): bool
    {
        return $this->extraSeatPriceFor($cycle)?->isPositive() === true;
    }

    /** Archived plans keep billing the teams already on them but cannot be newly chosen. */
    public function isArchived(): bool
    {
        return ! $this->is_active;
    }

    public function isFree(): bool
    {
        return (int) $this->price_monthly_cents === 0;
    }
}
