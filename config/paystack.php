<?php

/*
|--------------------------------------------------------------------------
| Paystack
|--------------------------------------------------------------------------
| TEST MODE ONLY unless you say otherwise twice. A secret key starting "sk_live_" is refused (never
| sent, logged without the key) unless PAYSTACK_ALLOW_LIVE=true is ALSO set. Keys are read from the
| environment only; this file never contains one. See README, "Paystack".
*/

return [

    // Server-side key: sk_test_... in test mode. Blank = online payment is switched off.
    'secret_key' => env('PAYSTACK_SECRET_KEY'),

    // pk_test_... Kept for completeness. The redirect flow used here never needs it in the browser.
    'public_key' => env('PAYSTACK_PUBLIC_KEY'),

    // Pinned to https. Anything else is refused.
    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

    // The second switch a live key needs. Leave false until the owner has decided to take real money.
    'allow_live' => (bool) env('PAYSTACK_ALLOW_LIVE', false),

    // Seconds, for connecting and for the whole request. No redirects are followed, no retries are made.
    'timeout' => 10,

    // Minutes a started payment is reused ("Pay now" twice reopens it) before it is retired.
    'attempt_ttl_minutes' => (int) env('PAYSTACK_ATTEMPT_TTL_MINUTES', 30),

];
