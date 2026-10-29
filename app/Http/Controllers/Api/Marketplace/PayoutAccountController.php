<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\PayoutAccounts;
use App\Support\Redacted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/payout-accounts: create or update a host's Paystack subaccount (see PayoutAccounts). The answer
 * is {id, status, last4, bank_code}; the account number is never returned, stored or logged.
 */
class PayoutAccountController extends Controller
{
    public function store(Request $request, PayoutAccounts $accounts): JsonResponse
    {
        $data = $request->validate([
            'host_ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'business_name' => ['required', 'string', 'max:120'],
            'settlement_bank' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'account_number' => ['required', 'string', 'regex:/^[0-9]{5,20}$/'],
        ]);

        [$account, $created] = $accounts->save(
            $data['host_ref'],
            trim($data['business_name']),
            $data['settlement_bank'],
            new Redacted($data['account_number']),
        );

        return response()->json([
            'id' => $account->id,
            'status' => $account->status,
            'last4' => $account->account_last4,
            'bank_code' => $account->bank_code,
        ], $created ? 201 : 200);
    }
}
