<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A company InfoDot has told Billing about (POST /api/companies), see docs/company-registry.md. It is not a
 * Team: no team or user is created from it. deleted_at is when InfoDot said the company was deleted.
 */
class InfodotCompany extends Model
{
    protected $table = 'infodot_companies';

    protected $fillable = ['company_uuid', 'name', 'registered_at', 'deleted_at'];

    protected $casts = [
        'registered_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
