<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingInvoice extends Model
{
    use HasTeamScope;

    protected $table = 'billing_invoices';

    protected $fillable = [
        'team_id', 'invoice_number', 'status', 'subtotal', 'tax', 'total',
        'currency', 'due_date', 'paid_at', 'stripe_invoice_id', 'pdf_url',
    ];

    protected $casts = [
        'due_date'  => 'datetime',
        'paid_at'   => 'datetime',
        'total'     => 'decimal:2',
        'subtotal'  => 'decimal:2',
        'tax'       => 'decimal:2',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingInvoiceItem::class, 'invoice_id');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_date?->isPast();
    }
}
