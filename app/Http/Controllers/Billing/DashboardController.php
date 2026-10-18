<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingAlert;
use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        // Team scoping comes from each model's HasTeamScope global scope.
        $subscription = BillingSubscription::with('plan')->latest('id')->first();

        $outstanding = BillingInvoice::where('status', 'open')
            ->selectRaw('currency, SUM(total_cents - amount_paid_cents) as cents')
            ->groupBy('currency')
            ->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'cents' => (int) $row->cents]);

        $paidYtd = BillingInvoice::where('status', 'paid')
            ->whereYear('paid_at', now()->year)
            ->selectRaw('currency, SUM(total_cents) as cents')
            ->groupBy('currency')
            ->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'cents' => (int) $row->cents]);

        $overdue = BillingInvoice::overdue()->count();

        return view('dashboard', [
            'planName' => $subscription?->plan?->name ?? 'No Plan',
            'subscription' => $subscription,
            'openInvoices' => BillingInvoice::where('status', 'open')->count(),
            'overdueInvoices' => $overdue,
            'outstandingByCurrency' => Money::totalsByCurrency($outstanding),
            'paidYtdByCurrency' => Money::totalsByCurrency($paidYtd),
            'activeAlerts' => BillingAlert::where('status', 'active')->count(),
            'attention' => $this->attention($overdue, $subscription),
            'team' => Auth::user()->currentTeam,
        ]);
    }

    /**
     * One honest sentence about whether anything needs a person, before any figure.
     *
     * @return array{level: string, text: string}
     */
    private function attention(int $overdue, ?BillingSubscription $subscription): array
    {
        if ($overdue > 0) {
            return ['level' => 'bad', 'text' => $overdue === 1 ? '1 invoice is overdue.' : "{$overdue} invoices are overdue."];
        }

        if ($subscription?->status === 'past_due') {
            return ['level' => 'bad', 'text' => 'The subscription is past due.'];
        }

        if ($subscription?->isLive() && $subscription->cancel_at_period_end) {
            return ['level' => 'attn', 'text' => 'The subscription ends on '.$subscription->current_period_end?->format('j M Y').'.'];
        }

        return ['level' => 'ok', 'text' => 'Nothing needs your attention.'];
    }
}
