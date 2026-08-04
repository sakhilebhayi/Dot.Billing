<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingAlert extends Model
{
    use HasTeamScope;

    protected $table = 'billing_alerts';

    protected $fillable = [
        'team_id', 'type', 'threshold_metric', 'threshold_value', 'status', 'triggered_at',
    ];

    protected $casts = [
        'triggered_at'    => 'datetime',
        'threshold_value' => 'decimal:2',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
