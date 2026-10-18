<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Models\Concerns\SyncsLegacyDecimals;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BillingPayment extends Model
{
    use HasTeamScope;
    use SyncsLegacyDecimals;

    protected $table = 'billing_payments';

    /** Mirror column defaults so a freshly created instance is usable without a refresh. */
    protected $attributes = [
        'currency' => 'ZAR',
    ];

    /** @var array<string, string> */
    protected array $legacyDecimals = ['amount' => 'amount_cents'];

    protected $fillable = [
        'team_id', 'invoice_id', 'amount', 'amount_cents', 'currency', 'status',
        'method', 'stripe_payment_id', 'failure_reason', 'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_cents' => 'integer',
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

    public function amountMoney(): Money
    {
        return new Money((int) $this->amount_cents, $this->currency);
    }

    public function event(): HasOne
    {
        return $this->hasOne(PaymentEvent::class, 'payment_id');
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
