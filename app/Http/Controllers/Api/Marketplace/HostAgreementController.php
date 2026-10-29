<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Exceptions\Payments\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\PayoutAccount;
use App\Services\Marketplace\HostDebts;
use App\Services\Marketplace\MarketplaceConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/payout-accounts/{id}/agreement: InfoDot tells Billing that a host accepted a version of the host term
 * (docs/host-agreement-draft.md). Idempotent per (account, version): the first acceptance stands and a repeat answers
 * 200 with it. Only the CURRENT version (billing.marketplace.agreement_version) lets tickets be created for the host.
 */
class HostAgreementController extends Controller
{
    public function store(Request $request, string $id, HostDebts $debts, MarketplaceConfig $config): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'accepted_by_ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        $account = PayoutAccount::find((int) $id) ?? throw MarketplaceException::because('not_found', 'No such payout account.', 404);

        [$agreement, $created] = $debts->accept($account, $data['version'], $data['accepted_by_ref']);

        return response()->json([
            'payout_account_id' => $account->id,
            'version' => $agreement->version,
            'accepted_at' => $agreement->accepted_at->toIso8601String(),
            'accepted_by_ref' => $agreement->accepted_by_ref,
            'current_version' => $config->agreementVersion(),
            'current' => $agreement->version === $config->agreementVersion(),
        ], $created ? 201 : 200);
    }
}
