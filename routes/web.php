<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Models\BillingAlert;
use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->name('ecosystem.auth');

Route::get('/', fn () => view('welcome'));

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively. There's no Jetstream equivalent for a Cookie Policy, so this one is wired by hand,
// following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        // team scoping now comes from each model's HasTeamScope global scope
        $subscription = BillingSubscription::with('plan')->first();

        return view('dashboard', [
            'planName'     => $subscription?->plan?->name ?? 'No Plan',
            'openInvoices' => BillingInvoice::where('status', 'open')->count(),
            'totalPaidYtd' => BillingInvoice::where('status', 'paid')->whereYear('paid_at', now()->year)->sum('total'),
            'activeAlerts' => BillingAlert::where('status', 'active')->count(),
        ]);
    })->name('dashboard');

    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
});
