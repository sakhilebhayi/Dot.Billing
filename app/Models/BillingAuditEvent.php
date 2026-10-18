<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingAuditEvent extends Model
{
    use HasTeamScope;

    public const UPDATED_AT = null;

    protected $table = 'billing_audit_events';

    protected $fillable = ['team_id', 'actor_id', 'action', 'subject_type', 'subject_id', 'meta'];

    protected $casts = [
        'meta' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
