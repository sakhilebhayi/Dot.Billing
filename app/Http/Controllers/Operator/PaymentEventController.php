<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\PaymentEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only list of payment events across every company, for platform operators (the `operator`
 * middleware). Events that need a person (unmatched, needs_review) are counted and highlighted.
 */
class PaymentEventController extends Controller
{
    public function index(Request $request): View
    {
        $attentionOnly = $request->query('show') === 'attention';

        $events = PaymentEvent::withoutGlobalScope('team')
            ->with([
                'team',
                'invoice' => fn ($query) => $query->withoutGlobalScope('team'),
            ])
            ->when($attentionOnly, fn ($query) => $query->whereIn('status', PaymentEvent::NEEDS_ATTENTION))
            ->orderByDesc('received_at')->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('operator.payment-events', [
            'events' => $events,
            'attentionCount' => PaymentEvent::withoutGlobalScope('team')->whereIn('status', PaymentEvent::NEEDS_ATTENTION)->count(),
            'attentionOnly' => $attentionOnly,
        ]);
    }
}
