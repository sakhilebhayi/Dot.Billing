<?php

namespace App\Services\Billing;

use Illuminate\Http\Request;

/**
 * Is this server to server call really from the platform it names, and fresh?
 *
 * Same scheme as InfoDot's other signed calls (Dot.Files' SignedRequest, Dot.Notify's IngestSignature):
 * HMAC-SHA256 over "{timestamp}.{raw body}" with the calling platform's own secret, sent as
 * "sha256=<hex>" in X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp. The body of a GET is
 * empty, so the signed string is "{timestamp}.". Five minutes either way, so a captured request goes
 * stale, and one platform's secret cannot sign for another.
 *
 * Note what the scheme does NOT cover: the URL. A captured signed request is valid, for five minutes,
 * for any company uuid. That is acceptable because a caller holding the platform secret can ask about
 * any company anyway; it is the scheme's known limit, kept for compatibility with the other apps.
 */
class EntitlementSignature
{
    public static function valid(Request $request, string $secret): bool
    {
        $timestamp = (int) $request->header('X-InfoDot-Timestamp');
        $signature = (string) $request->header('X-InfoDot-Signature');

        // Always do the HMAC, even for an unconfigured platform, so a blank-secret platform costs
        // the same as a wrong signature and the two cannot be told apart by timing.
        $key = $secret !== '' ? $secret : hash('sha256', 'billing-entitlements-unconfigured-platform');
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $key);

        $fresh = $timestamp !== 0 && abs(now()->timestamp - $timestamp) <= (int) config('billing.entitlements.tolerance', 300);

        // Callers may send the bare hex digest; accept it as well as "sha256=<hex>".
        $given = str_starts_with($signature, 'sha256=') ? $signature : 'sha256='.$signature;

        return hash_equals($expected, $given) && $fresh && $secret !== '';
    }
}
