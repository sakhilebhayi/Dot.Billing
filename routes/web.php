<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Models\BillingAlert;
use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use Illuminate\Support\Facades\Route;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->name('ecosystem.auth');

Route::get('/', fn () => view('welcome'));

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $team         = auth()->user()->currentTeam;
        $subscription = BillingSubscription::where('team_id', $team->id)->with('plan')->first();

        return view('dashboard', [
            'planName'     => $subscription?->plan?->name ?? 'No Plan',
            'openInvoices' => BillingInvoice::where('team_id', $team->id)->where('status', 'open')->count(),
            'totalPaidYtd' => BillingInvoice::where('team_id', $team->id)->where('status', 'paid')->whereYear('paid_at', now()->year)->sum('total'),
            'activeAlerts' => BillingAlert::where('team_id', $team->id)->where('status', 'active')->count(),
        ]);
    })->name('dashboard');

    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
});
