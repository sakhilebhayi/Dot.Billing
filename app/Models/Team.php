<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
        ];
    }

    /** The team's most recent subscription, live or ended (what the billing panel describes). */
    public function subscription(): HasOne
    {
        return $this->hasOne(BillingSubscription::class)->latestOfMany();
    }

    /** The one subscription that currently entitles the team, if any. */
    public function liveSubscription(): HasOne
    {
        return $this->hasOne(BillingSubscription::class)
            ->whereIn('status', BillingSubscription::LIVE_STATUSES)
            ->latestOfMany();
    }

    /**
     * Whether the team has financial records that must outlive it (see the restrict-foreign-keys
     * migration). Bypasses the team scope: this is a fact about the team, not about the signed-in user.
     */
    public function hasBillingRecords(): bool
    {
        return BillingSubscription::withoutGlobalScope('team')->where('team_id', $this->id)->exists()
            || BillingInvoice::withoutGlobalScope('team')->where('team_id', $this->id)->exists()
            || BillingPayment::withoutGlobalScope('team')->where('team_id', $this->id)->exists()
            || PaymentEvent::withoutGlobalScope('team')->where('team_id', $this->id)->exists()
            || BillingAuditEvent::withoutGlobalScope('team')->where('team_id', $this->id)->exists();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(BillingSubscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function billingAlerts(): HasMany
    {
        return $this->hasMany(BillingAlert::class);
    }
}
