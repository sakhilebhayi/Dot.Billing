<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingUsageRecord extends Model
{
    use HasTeamScope;

    protected $table = 'billing_usage_records';

    protected $fillable = [
        'team_id', 'platform', 'metric', 'quantity', 'recorded_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'recorded_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
