<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Models\Concerns\SyncsLegacyDecimals;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingInvoice extends Model
{
    use HasTeamScope;
    use SyncsLegacyDecimals;

    protected $table = 'billing_invoices';

    /** Mirror column defaults so a freshly created instance is usable without a refresh. */
    protected $attributes = [
        'currency' => 'ZAR',
        'amount_paid_cents' => 0,
        'tax_rate_bps' => 0,
    ];

    /** @var array<string, string> legacy decimal column => cents column */
    protected array $legacyDecimals = ['subtotal' => 'subtotal_cents', 'tax' => 'tax_cents', 'total' => 'total_cents'];

    protected $fillable = [
        'team_id', 'invoice_number', 'status', 'subtotal', 'tax', 'total',
        'subtotal_cents', 'tax_cents', 'total_cents',
        'currency', 'due_date', 'paid_at', 'stripe_invoice_id', 'pdf_url',
        'subscription_id', 'issued_at', 'period_start', 'period_end', 'amount_paid_cents',
        'tax_rate_bps', 'grace_until', 'voided_at',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'paid_at' => 'datetime',
        'issued_at' => 'datetime',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'grace_until' => 'datetime',
        'voided_at' => 'datetime',
        'amount_paid_cents' => 'integer',
        'tax_rate_bps' => 'integer',
        'total' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'subtotal_cents' => 'integer',
        'tax_cents' => 'integer',
        'total_cents' => 'integer',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BillingSubscription::class, 'subscription_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class, 'invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingInvoiceItem::class, 'invoice_id');
    }

    public function totalMoney(): Money
    {
        return new Money((int) $this->total_cents, $this->currency);
    }

    public function subtotalMoney(): Money
    {
        return new Money((int) $this->subtotal_cents, $this->currency);
    }

    public function taxMoney(): Money
    {
        return new Money((int) $this->tax_cents, $this->currency);
    }

    public function amountPaidMoney(): Money
    {
        return new Money((int) $this->amount_paid_cents, $this->currency);
    }

    public function balanceMoney(): Money
    {
        return $this->totalMoney()->subtract($this->amountPaidMoney());
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isInGrace(): bool
    {
        return $this->grace_until !== null && $this->grace_until->isFuture();
    }

    /** Open, past its due date, and not inside a grace period a person granted. */
    public function isOverdue(): bool
    {
        return $this->status === 'open'
            && $this->due_date?->isPast() === true
            && ! $this->isInGrace();
    }

    /**
     * @param  Builder<BillingInvoice>  $query
     */
    public function scopeOverdue(Builder $query, ?CarbonInterface $now = null): void
    {
        $now ??= now();

        $query->where('status', 'open')
            ->where('due_date', '<', $now)
            ->where(fn (Builder $q) => $q->whereNull('grace_until')->orWhere('grace_until', '<=', $now));
    }
}
