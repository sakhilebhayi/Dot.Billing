<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Exceptions\Payments\MarketplaceException;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceConfig;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/payout-banks: the South African (ZAR) banks a host can be paid into, as [{name, code}], from
 * Paystack, cached for six hours. A failed lookup is never cached.
 */
class PayoutBanksController extends Controller
{
    public const CACHE_KEY = 'billing.marketplace.payout-banks.za';

    public function __invoke(PaystackConfig $paystack, PaystackClient $client, MarketplaceConfig $config): JsonResponse
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return response()->json($cached);
        }

        if (! $paystack->canTakePayments()) {
            throw MarketplaceException::providerUnavailable();
        }

        try {
            $banks = $client->listBanks();
        } catch (PaymentGatewayException) {
            throw MarketplaceException::providerUnavailable();
        }

        Cache::put(self::CACHE_KEY, $banks, $config->banksCacheSeconds());

        return response()->json($banks);
    }
}
