<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One online payment someone started for an invoice. The provider's reference ties its webhook and its
 * redirect back to this row; the amount is what the invoice owed when it started and is the only amount
 * a provider confirmation is accepted for.
 */
class PaymentAttempt extends Model
{
    use HasTeamScope;

    public const INITIATED = 'initiated';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'uuid', 'team_id', 'invoice_id', 'provider', 'reference', 'amount_cents', 'currency', 'status',
        'authorization_url', 'initiated_by', 'expires_at', 'completed_at', 'failure_reason',
    ];

    /** The checkout address embeds the provider's access code, so it is never serialised. */
    protected $hidden = ['authorization_url'];

    protected $casts = [
        'amount_cents' => 'integer',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $attempt) {
            $attempt->uuid ??= (string) Str::uuid();
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'invoice_id')->withoutGlobalScope('team');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function amountMoney(): Money
    {
        return new Money($this->amount_cents, $this->currency);
    }

    public function isLive(): bool
    {
        return $this->status === self::INITIATED && $this->expires_at?->isFuture() === true;
    }
}
