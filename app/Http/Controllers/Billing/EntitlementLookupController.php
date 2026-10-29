<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\InfodotCompany;
use App\Models\Team;
use App\Services\Billing\EntitlementResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Operator-only: shows what the entitlements API would answer for a company id, so a support person
 * can answer "why does Dot.Files think this company has 3 seats?" without minting a signed request.
 */
class EntitlementLookupController extends Controller
{
    public function __invoke(Request $request, EntitlementResolver $resolver): View
    {
        $company = trim((string) $request->query('company', ''));
        $team = null;
        $entitlement = null;
        $registered = null;

        if ($company !== '' && Str::isUuid($company)) {
            $team = Team::where('infodot_team_uuid', strtolower($company))->first();
            $entitlement = $team ? $resolver->forTeam($team) : null;
            $registered = $team ? null : InfodotCompany::where('company_uuid', strtolower($company))->first();
        }

        return view('operator.entitlements', [
            'company' => $company,
            'team' => $team,
            'registered' => $registered,
            'entitlement' => $entitlement,
            'searched' => $company !== '',
        ]);
    }
}
