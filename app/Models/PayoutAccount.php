<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A seminar host's payout destination, as a Paystack subaccount. The bank account number is never kept: only
 * Paystack's subaccount code (encrypted at rest, never serialised) and the last four digits for display.
 */
class PayoutAccount extends Model
{
    public const ACTIVE = 'active';

    public const DISABLED = 'disabled';

    protected $fillable = ['host_ref', 'paystack_subaccount_code', 'bank_code', 'account_last4', 'business_name', 'status'];

    protected $hidden = ['paystack_subaccount_code'];

    protected function casts(): array
    {
        return ['paystack_subaccount_code' => 'encrypted'];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(TicketPayment::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
