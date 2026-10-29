<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingSubscription extends Model
{
    use HasTeamScope;

    protected $table = 'billing_subscriptions';

    /** A team may hold at most one subscription in these statuses (see the partial unique index). */
    public const LIVE_STATUSES = ['active', 'trialing', 'past_due'];

    protected $fillable = [
        'team_id', 'plan_id', 'status', 'billing_cycle',
        'trial_ends_at', 'current_period_start', 'current_period_end',
        'canceled_at', 'ended_at', 'cancel_at_period_end',
        'pending_plan_id', 'pending_billing_cycle', 'stripe_subscription_id',
        'extra_seats', 'pending_extra_seats',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'canceled_at' => 'datetime',
        'ended_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'extra_seats' => 'integer',
        'pending_extra_seats' => 'integer',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'plan_id');
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'pending_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class, 'subscription_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing']);
    }

    public function isTrialing(): bool
    {
        return $this->status === 'trialing' && $this->trial_ends_at?->isFuture();
    }
}
