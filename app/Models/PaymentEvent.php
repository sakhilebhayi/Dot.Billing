<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment fact as it arrived (see the payment_events migration). Written only by RecordPayment.
 */
class PaymentEvent extends Model
{
    use HasTeamScope;

    protected $fillable = [
        'team_id', 'invoice_id', 'payment_id', 'provider', 'external_id', 'type',
        'amount_cents', 'currency', 'payload', 'received_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'payment_id');
    }

    public function amountMoney(): Money
    {
        return new Money($this->amount_cents, $this->currency);
    }
}
