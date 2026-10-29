<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One paid-seminar ticket. Pending until a Paystack-verified charge.success marks it paid; refunded only when
 * Paystack's refund.processed arrives; failed when the host cancels it while unpaid (failure_reason
 * "cancelled"), after which it can never be paid. The checkout address embeds Paystack's access code, so it is
 * never serialised.
 */
class TicketPayment extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const REFUNDED = 'refunded';

    public const FAILED = 'failed';

    protected $fillable = [
        'ticket_ref', 'payout_account_id', 'buyer_email', 'amount_cents', 'platform_fee_cents', 'currency', 'description',
        'return_url', 'paystack_reference', 'authorization_url', 'paystack_transaction_id', 'status', 'failure_reason',
        'refund_requested_at', 'paid_at', 'refunded_at',
    ];

    protected $hidden = ['authorization_url'];

    protected $casts = [
        'amount_cents' => 'integer',
        'platform_fee_cents' => 'integer',
        'refund_requested_at' => 'datetime',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(): array
    {
        return [
            'ticket_ref' => $this->ticket_ref,
            'status' => $this->status,
            'amount_cents' => $this->amount_cents,
            'platform_fee_cents' => $this->platform_fee_cents,
            'currency' => $this->currency,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
        ];
    }
}
