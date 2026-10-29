<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Paystack dispute (chargeback) against a seminar ticket, recorded from the webhook. Billing never submits
 * evidence or resolves a dispute: a person does that in the Paystack dashboard. `resolution` is Paystack's
 * outcome normalised to won, lost or other; the raw text is kept in paystack_resolution.
 */
class TicketDispute extends Model
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const WON = 'won';

    public const LOST = 'lost';

    public const OTHER = 'other';

    protected $fillable = ['ticket_payment_id', 'paystack_dispute_id', 'status', 'resolution', 'paystack_resolution', 'opened_at', 'resolved_at'];

    protected $casts = ['opened_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketPayment::class, 'ticket_payment_id');
    }
}
