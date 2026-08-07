<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingPlan extends Model
{
    protected $table = 'billing_plans';

    protected $fillable = [
        'name', 'slug', 'description', 'price_monthly', 'price_annual',
        'currency', 'seat_limit', 'storage_gb', 'features', 'is_active',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'price_monthly' => 'decimal:2',
        'price_annual' => 'decimal:2',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(BillingSubscription::class, 'plan_id');
    }

    public function isFree(): bool
    {
        return $this->price_monthly == 0;
    }
}
