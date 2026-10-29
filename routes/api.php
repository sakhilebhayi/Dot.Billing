<?php

use App\Http\Controllers\Api\EntitlementController;
use App\Http\Controllers\Api\Marketplace\PayoutAccountController;
use App\Http\Controllers\Api\Marketplace\PayoutBanksController;
use App\Http\Controllers\Api\Marketplace\SeminarTicketController;
use App\Http\Middleware\EnsureMarketplaceCaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Server-to-server: what is this company entitled to? No session or Sanctum; the calling platform
// proves itself with a per-platform HMAC signature (see EntitlementSignature).
Route::get('/entitlements/{companyUuid}', EntitlementController::class)
    ->middleware('throttle:120,1')
    ->name('api.entitlements.show');

// Seminar ticket marketplace (docs/seminar-payments.md). Server to server, signed by the platform "infodot"
// exactly like the entitlements call (the raw body is signed for a POST). OFF unless
// billing.marketplace.enabled: every route below is then a 404. Parameters stay plain strings so nothing is
// looked up before EnsureMarketplaceCaller has authenticated the call.
Route::middleware(['throttle:120,1', EnsureMarketplaceCaller::class])->group(function () {
    Route::get('/payout-banks', PayoutBanksController::class)->name('api.marketplace.banks');
    Route::post('/payout-accounts', [PayoutAccountController::class, 'store'])->name('api.marketplace.payout-accounts.store');
    Route::post('/seminar-tickets', [SeminarTicketController::class, 'store'])->name('api.marketplace.tickets.store');
    Route::get('/seminar-tickets/{ticketRef}', [SeminarTicketController::class, 'show'])->where('ticketRef', '[A-Za-z0-9._:-]{1,64}')->name('api.marketplace.tickets.show');
    Route::post('/seminar-tickets/{ticketRef}/refund', [SeminarTicketController::class, 'refund'])->where('ticketRef', '[A-Za-z0-9._:-]{1,64}')->name('api.marketplace.tickets.refund');
    Route::post('/seminar-tickets/{ticketRef}/cancel', [SeminarTicketController::class, 'cancel'])->where('ticketRef', '[A-Za-z0-9._:-]{1,64}')->name('api.marketplace.tickets.cancel');
});
