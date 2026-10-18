<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesTeams;

class DeleteTeam implements DeletesTeams
{
    /**
     * Delete the given team, unless it has billing history: subscriptions, invoices and payments are
     * financial records and outlive the team (the database refuses too; this explains why).
     */
    public function delete(Team $team): void
    {
        if ($team->hasBillingRecords()) {
            throw ValidationException::withMessages([
                'team' => __('":name" has billing history (subscriptions, invoices or payments) that must be kept, so it cannot be deleted. Contact support if it must be closed.', ['name' => $team->name]),
            ]);
        }

        $team->purge();
    }
}
