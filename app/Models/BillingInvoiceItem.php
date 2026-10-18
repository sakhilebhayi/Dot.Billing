<?php

namespace App\Models;

use App\Models\Concerns\SyncsLegacyDecimals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoiceItem extends Model
{
    use SyncsLegacyDecimals;

    protected $table = 'billing_invoice_items';

    /** @var array<string, string> */
    protected array $legacyDecimals = ['unit_price' => 'unit_price_cents', 'amount' => 'amount_cents'];

    protected $fillable = [
        'invoice_id', 'description', 'quantity', 'unit_price', 'amount', 'unit_price_cents', 'amount_cents', 'period',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'unit_price_cents' => 'integer',
        'amount_cents' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'invoice_id');
    }
}
