<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Create a new action instance.
     */
    public function __construct(protected DeletesTeams $deletesTeams) {}

    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        // Check every owned team BEFORE touching any, so a refusal leaves nothing half-deleted.
        foreach ($user->ownedTeams as $team) {
            if ($team->hasBillingRecords()) {
                throw ValidationException::withMessages([
                    'password' => __('Your account owns ":name", which has billing history that must be kept, so the account cannot be deleted yet. Contact support.', ['name' => $team->name]),
                ]);
            }
        }

        DB::transaction(function () use ($user) {
            $this->deleteTeams($user);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            $user->delete();
        });
    }

    /**
     * Delete the teams and team associations attached to the user.
     */
    protected function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        $user->ownedTeams->each(function (Team $team) {
            $this->deletesTeams->delete($team);
        });
    }
}
