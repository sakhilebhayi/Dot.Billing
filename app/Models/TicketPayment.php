<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One paid-seminar ticket. Pending until a Paystack-verified charge.success marks it paid. Refunds are applied
 * only when Paystack's refund.processed arrives: partially_refunded while refunded_cents is below the amount,
 * refunded when it equals it. disputed while a Paystack dispute is open (no refunds then), chargeback when one is
 * lost, back to the resting paid/partially_refunded/refunded status when one is won. failed when the host cancels
 * it while unpaid (failure_reason "cancelled"), after which it can never be paid. The checkout address embeds
 * Paystack's access code, so it is never serialised.
 */
class TicketPayment extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const REFUNDED = 'refunded';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public const FAILED = 'failed';

    public const DISPUTED = 'disputed';

    public const CHARGEBACK = 'chargeback';

    /** Statuses that mean a payment was received at some point. */
    public const PAID_STATES = [self::PAID, self::PARTIALLY_REFUNDED, self::REFUNDED, self::DISPUTED, self::CHARGEBACK];

    protected $fillable = [
        'ticket_ref', 'payout_account_id', 'buyer_email', 'amount_cents', 'platform_fee_cents', 'currency', 'description',
        'return_url', 'paystack_reference', 'authorization_url', 'paystack_transaction_id', 'status', 'failure_reason',
        'refunded_cents', 'refund_requested_at', 'paid_at', 'refunded_at',
        'recovery_cents', 'recovery_applied_cents', 'recovery_reversed_cents',
    ];

    protected $hidden = ['authorization_url'];

    protected $casts = [
        'amount_cents' => 'integer',
        'platform_fee_cents' => 'integer',
        'refunded_cents' => 'integer',
        'recovery_cents' => 'integer',
        'recovery_applied_cents' => 'integer',
        'recovery_reversed_cents' => 'integer',
        'refund_requested_at' => 'datetime',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(TicketRefund::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(TicketDispute::class);
    }

    /**
     * InfoDot's whole take on this ticket, in cents: the platform fee plus any extra recovery of a lost chargeback.
     * This is the flat transaction_charge sent to Paystack.
     */
    public function chargeCents(): int
    {
        return $this->platform_fee_cents + $this->recovery_cents;
    }

    /** The host's share before Paystack's processing fee: the price minus the platform fee (never reduced by recovery). */
    public function hostShareCents(): int
    {
        return max(0, $this->amount_cents - $this->platform_fee_cents);
    }

    /** The status this ticket has when no dispute is in the way, from what has been refunded so far. */
    public function restingStatus(): string
    {
        return match (true) {
            $this->refunded_cents >= $this->amount_cents => self::REFUNDED,
            $this->refunded_cents > 0 => self::PARTIALLY_REFUNDED,
            default => self::PAID,
        };
    }

    /** "open" or "resolved" for the latest dispute on this ticket, null when it has never had one. */
    public function disputeStatus(): ?string
    {
        return $this->disputes()->orderByDesc('id')->value('status');
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
            'recovery_cents' => $this->recovery_cents,
            'currency' => $this->currency,
            'refunded_cents' => $this->refunded_cents,
            'dispute_status' => $this->disputeStatus(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
        ];
    }
}
