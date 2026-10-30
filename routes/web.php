<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\Billing\DashboardController;
use App\Http\Controllers\Billing\EntitlementLookupController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\InvoicePaymentController;
use App\Http\Controllers\Billing\SeatsController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\Operator\PaymentEventController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->middleware('throttle:10,1')
    ->name('ecosystem.auth');

// Browsers POST Content-Security-Policy violation reports here (the policy is report-only, see SecurityHeaders). The browser sends
// no cookie, CSRF token or login, so the route drops the web group's session and CSRF middleware: a report neither starts a
// session nor needs one. Throttled per IP; the controller keeps only the violated directive and the blocked host.
Route::post('/csp-report', CspReportController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:60,1,csp-report')
    ->name('csp.report');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

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
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');

    // Extra seats: owners and admins only (manage-billing); buying goes through SeatChange and pays through the invoice route below.
    Route::get('/seats', SeatsController::class)->name('seats.show');

    // Paystack: start a payment, and where the payer lands afterwards. The webhook is in routes/webhooks.php.
    Route::post('/invoices/{invoice}/pay', [InvoicePaymentController::class, 'store'])
        ->middleware('throttle:20,1')->name('invoices.pay');
    Route::get('/billing/paystack/callback/{attempt}', [InvoicePaymentController::class, 'callback'])
        ->whereUuid('attempt')->middleware('throttle:30,1')->name('paystack.callback');

    Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
        Route::get('/dunning-cases', fn () => view('operator.dunning-cases'))->name('dunning-cases.index');
        Route::get('/plans', fn () => view('operator.plans'))->name('plans.index');
        Route::get('/entitlements', EntitlementLookupController::class)->name('entitlements.index');
        Route::get('/payment-events', [PaymentEventController::class, 'index'])->name('payment-events.index');
    });
});

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}
