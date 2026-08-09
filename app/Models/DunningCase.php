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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
