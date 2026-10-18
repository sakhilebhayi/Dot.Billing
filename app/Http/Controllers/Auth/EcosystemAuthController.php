<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class EcosystemAuthController extends Controller
{
    public function handle(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): RedirectResponse
    {
        $token = $request->query('token');

        // A missing, empty or array token (?token[]=x) is a clean 403, never a 500.
        abort_unless(is_string($token) && $token !== '', 403);

        $user = $this->userFromLocalToken($token);

        // Production gives each Dot app its own database, so InfoDot's token is not
        // in ours: ask InfoDot to prove it.
        if (! $user && $infoDot->enabled()) {
            try {
                $identity = $infoDot->redeem($token);
            } catch (InfoDotUnavailable) {
                abort(503, 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.');
            }

            $user = $identity ? $provision($identity) : null;

            if ($user && $identity) {
                $syncTeams($user, $identity);
            }
        }

        abort_if(! $user, 403);

        $this->settleCurrentTeam($user);

        Auth::login($user);

        // A new session id at the moment of login, so a session id planted before it is useless.
        $request->session()->regenerate();

        return redirect()->to($this->safeRedirect($request->query('redirect')) ?? route('dashboard'));
    }

    /**
     * Every query here is scoped to the person's current team (HasTeamScope), and that scope
     * trusts it. So make sure it is a team they really belong to: otherwise fall back to their
     * personal team, or to none (the scope then fails closed and shows nothing).
     */
    private function settleCurrentTeam(User $user): void
    {
        $user->unsetRelation('teams')->unsetRelation('ownedTeams')->unsetRelation('currentTeam');

        $current = $user->current_team_id ? Team::find($user->current_team_id) : null;

        if ($current && $user->belongsToTeam($current)) {
            return;
        }

        $fallback = $user->personalTeam();

        if ($fallback) {
            $user->switchTeam($fallback);

            return;
        }

        if ($user->current_team_id !== null) {
            $user->forceFill(['current_team_id' => null])->save();
        }
    }

    /**
     * A deep link names a page in this app. Only a plain path is honoured: one leading slash,
     * no "//" (protocol-relative), no backslash, no ".." and no control characters, also after
     * decoding. Anything else falls back to the dashboard, so this can never be an open redirect.
     */
    private function safeRedirect(mixed $redirect): ?string
    {
        if (! is_string($redirect) || $redirect === '' || strlen($redirect) > 2048) {
            return null;
        }

        foreach ([$redirect, rawurldecode($redirect), rawurldecode(rawurldecode($redirect))] as $form) {
            if (
                $form[0] !== '/'
                || str_starts_with($form, '//')
                || str_contains($form, '\\')
                || str_contains($form, '..')
                || str_contains($form, '//')
                || preg_match('/[\x00-\x1f\x7f]/', $form)
            ) {
                return null;
            }
        }

        return $redirect;
    }

    /** A token minted in a database we share (the original design): found, checked, spent. */
    private function userFromLocalToken(string $token): ?User
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast())
        ) {
            return null;
        }

        $user = $accessToken->tokenable;
        $accessToken->delete();

        return $user instanceof User ? $user : null;
    }
}
