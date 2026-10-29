<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money InfoDot lost to a chargeback on one of a host's tickets and may recover, under the host's accepted term,
 * from a larger fee on that host's later tickets. One row per lost dispute (ticket_payment_id is unique).
 *
 * open: recovered_cents < amount_cents. cleared: fully recovered. written_off: a moderator gave up the rest.
 * What is still owed is amount_cents minus recovered_cents while open, and nothing otherwise.
 */
class HostDebt extends Model
{
    public const OPEN = 'open';

    public const CLEARED = 'cleared';

    public const WRITTEN_OFF = 'written_off';

    protected $fillable = [
        'payout_account_id', 'ticket_payment_id', 'currency', 'amount_cents', 'recovered_cents', 'status',
        'cleared_at', 'written_off_at', 'written_off_by_ref', 'note',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'recovered_cents' => 'integer',
        'cleared_at' => 'datetime',
        'written_off_at' => 'datetime',
    ];

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketPayment::class, 'ticket_payment_id');
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(HostDebtRecovery::class);
    }

    public function remainingCents(): int
    {
        return $this->status === self::OPEN ? max(0, $this->amount_cents - $this->recovered_cents) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'ticket_ref' => $this->ticket->ticket_ref,
            'currency' => $this->currency,
            'amount_cents' => $this->amount_cents,
            'recovered_cents' => $this->recovered_cents,
            'remaining_cents' => $this->remainingCents(),
            'written_off_cents' => $this->status === self::WRITTEN_OFF ? max(0, $this->amount_cents - $this->recovered_cents) : 0,
            'status' => $this->status,
            'opened_at' => $this->created_at?->toIso8601String(),
            'cleared_at' => $this->cleared_at?->toIso8601String(),
            'written_off_at' => $this->written_off_at?->toIso8601String(),
            'written_off_by_ref' => $this->written_off_by_ref,
            'note' => $this->note,
        ];
    }
}
