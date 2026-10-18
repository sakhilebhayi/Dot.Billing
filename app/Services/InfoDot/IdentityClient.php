<?php

namespace App\Services\InfoDot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asks InfoDot (the identity hub) to prove a handoff token.
 *
 * Production gives every Dot app its own database, so the token InfoDot minted is
 * not in ours. We send it to InfoDot's redeem endpoint with this platform's secret.
 * Returns the identity array on success, null when InfoDot refuses the token (4xx),
 * and throws InfoDotUnavailable when InfoDot cannot be reached or errors (so the
 * caller can say "try again" instead of "forbidden").
 */
class IdentityClient
{
    public function enabled(): bool
    {
        return filled(config('ecosystem.infodot.secret')) && filled(config('ecosystem.infodot.redeem_url'));
    }

    /**
     * @return array{user: array<string, mixed>, teams: list<array<string, mixed>>, current_team_id: mixed}|null
     */
    public function redeem(string $token): ?array
    {
        try {
            $response = Http::withToken((string) config('ecosystem.infodot.secret'))
                ->acceptJson()
                ->timeout((int) config('ecosystem.infodot.timeout', 5))
                ->post((string) config('ecosystem.infodot.redeem_url'), [
                    'token' => $token,
                    'platform' => (string) config('ecosystem.infodot.platform'),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('InfoDot redeem unreachable.', ['error' => class_basename($e)]);

            throw new InfoDotUnavailable('InfoDot could not be reached.', 0, $e);
        }

        if ($response->serverError() || $response->status() === 429) {
            Log::warning('InfoDot redeem failed.', ['status' => $response->status()]);

            throw new InfoDotUnavailable('InfoDot is not available.');
        }

        if (! $response->successful()) {
            // 401 means this platform's secret is wrong: an operator problem, worth shouting about.
            Log::log($response->status() === 401 ? 'error' : 'info', 'InfoDot refused a handoff token.', ['status' => $response->status()]);

            return null;
        }

        $data = $response->json();

        return is_array($data) && isset($data['user']['email']) ? $data : null;
    }
}
