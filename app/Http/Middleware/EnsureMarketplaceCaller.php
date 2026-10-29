<?php

namespace App\Http\Middleware;

use App\Services\Billing\EntitlementSignature;
use App\Services\Marketplace\MarketplaceConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for every seminar marketplace endpoint, in this order:
 *
 *  1. marketplace off: a plain 404, before anything else is looked at;
 *  2. the caller must be the platform "infodot" and sign "{timestamp}.{raw body}" with ITS secret
 *     (EntitlementSignature, the same scheme as the entitlements API). An unknown or other platform, a blank
 *     secret, a bad or stale signature all give the same generic 401. This runs BEFORE any lookup, so an
 *     unauthenticated caller learns nothing about which hosts or tickets exist;
 *  3. a marketplace configuration that is not safe to take money with (see MarketplaceConfig::isValid): 503.
 *
 * Route parameters are plain strings on purpose: implicit model binding would look rows up before this runs.
 */
class EnsureMarketplaceCaller
{
    public function __construct(private MarketplaceConfig $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->config->enabled()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $platform = strtolower((string) $request->header('X-InfoDot-Platform'));
        $secret = $platform === MarketplaceConfig::CALLER ? (string) (config('billing.entitlements.secrets')[MarketplaceConfig::CALLER] ?? '') : '';

        if (! EntitlementSignature::valid($request, $secret)) {
            return response()->json(['error' => 'invalid_request'], 401);
        }

        if (! $this->config->isValid()) {
            Log::critical('marketplace.misconfigured', ['platform_percent_valid' => $this->config->platformPercent() !== null]);

            return response()->json(['error' => 'marketplace_misconfigured', 'message' => 'The marketplace is not configured safely and is refusing requests.'], 503);
        }

        return $next($request);
    }
}
