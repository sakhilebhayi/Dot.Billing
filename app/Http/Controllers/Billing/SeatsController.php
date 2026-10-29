<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * GET /seats: where a company's owner or admin buys and reduces extra seats (docs/seat-purchasing.md).
 * Same authorization as every other billing action (the manage-billing gate, against the CURRENT team): an
 * ordinary member, a viewer, or someone whose current team is not one they belong to gets a 403.
 */
class SeatsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $team = $request->user()->currentTeam;

        abort_unless($team, 403, 'No active team selected.');
        Gate::authorize('manage-billing', $team);

        return view('seats.show');
    }
}
