<?php

namespace App\Policies;

use App\Models\DunningCase;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DunningCasePolicy
{
    use HandlesAuthorization;

    public function review(User $user, DunningCase $case): bool
    {
        return (bool) $user->is_platform_operator;
    }
}
