<?php

use App\Http\Controllers\Billing\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

// Paystack calls this server to server. It carries no session, no CSRF token and no user: the request is
// authenticated by its x-paystack-signature header, verified over the raw body in the controller. The throttle
// is generous because Paystack retries; it is a flood guard, not a gate.
Route::post('/webhooks/paystack', PaystackWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.paystack');
