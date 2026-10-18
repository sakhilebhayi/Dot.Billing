<?php

use App\Http\Controllers\Api\EntitlementController;
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
