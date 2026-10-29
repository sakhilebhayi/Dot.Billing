<?php

namespace App\Http\Middleware;

use App\Services\Billing\EntitlementSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the company registry: only the platform "infodot" may call, signing "{timestamp}.{raw body}" with ITS
 * secret (EntitlementSignature, the same scheme as the entitlements API). An unknown or other platform, a blank
 * secret, a bad or stale signature all give the same generic 401, and this runs before anything is validated or
 * looked up, so an unauthenticated caller learns nothing about which companies exist.
 */
class EnsureInfoDotCaller
{
    public const CALLER = 'infodot';

    public function handle(Request $request, Closure $next): Response
    {
        $platform = strtolower((string) $request->header('X-InfoDot-Platform'));
        $secret = $platform === self::CALLER ? (string) (config('billing.entitlements.secrets')[self::CALLER] ?? '') : '';

        if (! EntitlementSignature::valid($request, $secret)) {
            return response()->json(['error' => 'invalid_request'], 401);
        }

        return $next($request);
    }
}
