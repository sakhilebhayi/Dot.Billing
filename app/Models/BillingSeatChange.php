<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to change a subscription's extra seats. kind: add (invoiced, applied when the invoice
 * settles), reduce (scheduled for the period end) or keep (no change; clears a scheduled reduction).
 */
class BillingSeatChange extends Model
{
    use HasTeamScope;

    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const SCHEDULED = 'scheduled';

    public const APPLIED = 'applied';

    public const SUPERSEDED = 'superseded';

    public const LAPSED = 'lapsed';

    protected $table = 'billing_seat_changes';

    protected $fillable = [
        'team_id', 'subscription_id', 'request_key', 'kind', 'from_extra_seats', 'to_extra_seats',
        'status', 'invoice_id', 'requested_by', 'applied_at',
    ];

    protected $casts = [
        'from_extra_seats' => 'integer',
        'to_extra_seats' => 'integer',
        'applied_at' => 'datetime',
    ];

    /** True on the instance returned for a request key that had already been handled. Not stored. */
    public bool $replayed = false;

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BillingSubscription::class, 'subscription_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'invoice_id');
    }
}
