<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The term a seminar host accepted (docs/host-agreement-draft.md), one row per payout account and version.
 * InfoDot shows the host the term and tells Billing who accepted which version; only the CURRENT version
 * (billing.marketplace.agreement_version) lets a ticket be created or a debt be recovered.
 */
class HostAgreement extends Model
{
    protected $fillable = ['payout_account_id', 'version', 'accepted_at', 'accepted_by_ref'];

    protected $casts = ['accepted_at' => 'datetime'];

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class);
    }
}
