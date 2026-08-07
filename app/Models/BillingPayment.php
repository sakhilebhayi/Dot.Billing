<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPayment extends Model
{
    use HasTeamScope;

    protected $table = 'billing_payments';

    protected $fillable = [
        'team_id', 'invoice_id', 'amount', 'currency', 'status',
        'method', 'stripe_payment_id', 'failure_reason', 'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class);
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
