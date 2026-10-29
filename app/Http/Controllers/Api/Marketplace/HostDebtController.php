<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Exceptions\Payments\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\HostDebt;
use App\Models\PayoutAccount;
use App\Services\Marketplace\HostDebts;
use App\Services\Marketplace\MarketplaceCallbackClient;
use App\Services\Marketplace\MarketplaceConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A host's chargeback debts (GET) and the moderator write-off (POST). Server to server, behind
 * EnsureMarketplaceCaller. A write-off is initiated by InfoDot's moderator and records who in written_off_by_ref.
 */
class HostDebtController extends Controller
{
    public function index(string $id, HostDebts $debts, MarketplaceConfig $config): JsonResponse
    {
        $account = PayoutAccount::find((int) $id) ?? throw MarketplaceException::because('not_found', 'No such payout account.', 404);

        $list = $account->debts()->with('ticket')->orderBy('id')->get();

        return response()->json([
            'payout_account_id' => $account->id,
            'agreement' => [
                'current_version' => $config->agreementVersion(),
                'accepted' => $debts->hasCurrentAgreement($account),
            ],
            'open_remaining_cents' => (int) $list->sum(fn (HostDebt $debt) => $debt->remainingCents()),
            'in_flight_recovery_cents' => $debts->inFlightCents($account->id),
            'debts' => $list->map(fn (HostDebt $debt) => $debt->payload())->values(),
        ]);
    }

    public function writeOff(Request $request, string $id, HostDebts $debts, MarketplaceCallbackClient $callbacks): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:1', 'max:500'],
            'written_off_by_ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        [$debt, $changed] = $debts->writeOff((int) $id, trim($data['note']), $data['written_off_by_ref']);

        if ($changed) {
            $callbacks->hostDebt(HostDebts::WRITTEN_OFF, $debt);
        }

        return response()->json($debt->load('ticket')->payload());
    }
}
