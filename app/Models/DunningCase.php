<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DunningCase extends Model
{
    protected $fillable = [
        'team_id', 'invoice_id', 'payment_id', 'reason', 'status',
        'resolved_at', 'resolved_by', 'resolution_notes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * BillingInvoice carries HasTeamScope, which fails closed (adds
     * `1 = 0`) for an authenticated user with no current team -- exactly
     * what a platform_operator reviewing a dunning case is, by design (see
     * app/Models/Concerns/HasTeamScope.php's own docblock). A dunning case
     * is inherently cross-team review, so this relation always bypasses
     * that scope rather than being silently unreachable from this model.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class)->withoutGlobalScope('team');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class)->withoutGlobalScope('team');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
