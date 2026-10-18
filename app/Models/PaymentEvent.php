<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment fact as it arrived (see the payment_events migrations). Applied payments are written only by
 * RecordPayment. A provider adapter may also write the events it could NOT apply, with status "unmatched"
 * or "needs_review" and a reason, so an operator can see them; those never touch an invoice.
 */
class PaymentEvent extends Model
{
    use HasTeamScope;

    public const APPLIED = 'applied';

    public const UNMATCHED = 'unmatched';

    public const NEEDS_REVIEW = 'needs_review';

    /** Statuses an operator has to look at. */
    public const NEEDS_ATTENTION = [self::UNMATCHED, self::NEEDS_REVIEW];

    protected $fillable = [
        'team_id', 'invoice_id', 'payment_id', 'provider', 'external_id', 'type',
        'amount_cents', 'currency', 'payload', 'received_at', 'status', 'reason',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'payment_id');
    }

    public function needsAttention(): bool
    {
        return in_array($this->status, self::NEEDS_ATTENTION, true);
    }

    public function amountMoney(): Money
    {
        return new Money($this->amount_cents, $this->currency);
    }
}
