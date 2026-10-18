<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Billing\EntitlementResolver;
use App\Services\Billing\EntitlementSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * GET /api/entitlements/{company_uuid}: another Dot app asks "what is this company on?".
 * Server to server; no user session.
 *
 * Authentication: the calling platform names itself in X-InfoDot-Platform and signs the request with its
 * own secret (config billing.entitlements.secrets, from BILLING_ENTITLEMENT_SECRET_<KEY>); see
 * EntitlementSignature. An unknown platform, a blank secret, and a bad or stale signature all return the
 * same generic 401, and authentication happens BEFORE the company is looked up, so an unauthenticated
 * caller cannot learn which companies exist.
 *
 * company_uuid is InfoDot's id for the company, matched against teams.infodot_team_uuid. An unknown
 * company is a generic 404.
 */
class EntitlementController extends Controller
{
    public function __invoke(Request $request, string $companyUuid, EntitlementResolver $resolver): JsonResponse|Response
    {
        $platform = strtolower((string) $request->header('X-InfoDot-Platform'));
        $secret = (string) (config('billing.entitlements.secrets')[$platform] ?? '');

        if (! EntitlementSignature::valid($request, $secret)) {
            return response()->json(['error' => 'invalid_request'], 401);
        }

        $team = Str::isUuid($companyUuid)
            ? Team::where('infodot_team_uuid', strtolower($companyUuid))->first()
            : null;

        if (! $team) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $payload = $resolver->forTeam($team);
        $etag = '"'.sha1((string) json_encode($payload)).'"';

        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response()->json($payload, 200, ['ETag' => $etag, 'Cache-Control' => 'private, no-cache']);
    }
}
