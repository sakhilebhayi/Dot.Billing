<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refund of a seminar ticket (the whole remaining amount or part of it). refund_key is InfoDot's
 * idempotency key, unique per ticket. It is processed only when Paystack's refund.processed says so.
 */
class TicketRefund extends Model
{
    public const PENDING = 'pending';

    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    protected $fillable = ['ticket_payment_id', 'refund_key', 'amount_cents', 'status', 'paystack_refund_id', 'processed_at'];

    protected $casts = ['amount_cents' => 'integer', 'processed_at' => 'datetime'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketPayment::class, 'ticket_payment_id');
    }
}
