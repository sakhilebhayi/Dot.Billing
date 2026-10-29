<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one ticket's larger fee was credited to one debt (amount_cents), and how much of that a later
 * refund of the ticket gave back (reversed_cents). Unique per (debt, ticket): a ticket cannot be credited twice.
 */
class HostDebtRecovery extends Model
{
    protected $fillable = ['host_debt_id', 'ticket_payment_id', 'amount_cents', 'reversed_cents'];

    protected $casts = ['amount_cents' => 'integer', 'reversed_cents' => 'integer'];

    public function debt(): BelongsTo
    {
        return $this->belongsTo(HostDebt::class, 'host_debt_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketPayment::class, 'ticket_payment_id');
    }
}
