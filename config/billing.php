<?php

// Platform keys come from the shared ecosystem registry, so a new Dot app only needs
// BILLING_ENTITLEMENT_SECRET_<KEY> in .env to be able to call the entitlements API.
$platformKeys = array_keys((require __DIR__.'/ecosystem.php')['platforms']);

/*
|--------------------------------------------------------------------------
| Dot.Billing settings
|--------------------------------------------------------------------------
| No payment provider is chosen or integrated. Invoices are raised by the app and
| money is recorded against them (manually today); see README, "Where a payment
| provider plugs in".
*/

return [

    'invoice' => [
        // Tokens: {YYYY} {YY} {MM} {seq}. The sequence is per team and restarts each year.
        // A format without {seq} gets "-{seq}" appended so numbers stay unique.
        'number_format' => env('BILLING_INVOICE_NUMBER_FORMAT', 'INV-{YYYY}-{seq}'),
        'number_pad' => (int) env('BILLING_INVOICE_NUMBER_PAD', 4),

        // Days between issuing an invoice and its due date.
        'payment_terms_days' => (int) env('BILLING_PAYMENT_TERMS_DAYS', 14),

        // true: generated invoices go straight to "open". false: they wait as "draft" for a manager to finalize.
        'auto_finalize' => (bool) env('BILLING_AUTO_FINALIZE_INVOICES', true),

        // A percentage, e.g. "15" or "7.5". Applied to the subtotal, rounded half up to the cent. 0 = no tax line.
        'tax_rate_percent' => env('BILLING_TAX_RATE', '0'),
    ],

    /*
    | Extra seats (see docs/seat-purchasing.md). These are defaults chosen by engineering and need the
    | owner's confirmation. Nothing here sets a price: a plan sells extra seats only when an operator has
    | set its extra_seat_price_* columns.
    |
    |  max_extra   the most extra seats one subscription may hold
    |  add_charge  'prorata'     charge the rest of the current period by actual days, rounded half up
    |              'full_period' charge a full period's price for the added seats straight away
    |
    | Reducing seats is always scheduled for the period end with no refund or credit; that is not a setting.
    */
    'seats' => [
        'max_extra' => (int) env('BILLING_SEATS_MAX_EXTRA', 500),
        'add_charge' => env('BILLING_SEATS_ADD_CHARGE', 'prorata'),
    ],

    // Printed on the invoice. Anything left blank is simply not printed; nothing is invented.
    'issuer' => [
        'name' => env('BILLING_ISSUER_NAME'),
        'address' => array_values(array_filter(array_map('trim', explode('|', (string) env('BILLING_ISSUER_ADDRESS', ''))))),
        'tax_number' => env('BILLING_ISSUER_TAX_NUMBER'),
        'email' => env('BILLING_ISSUER_EMAIL'),
    ],

    'entitlements' => [
        /*
        | GET /api/entitlements/{company_uuid} is server to server. The CALLING platform signs
        | "{timestamp}.{raw body}" (the body is empty for a GET) with HMAC-SHA256 using its own
        | secret and names itself in X-InfoDot-Platform; Billing verifies. A blank secret means
        | that platform cannot call at all. Generate with:
        |     php -r 'echo bin2hex(random_bytes(32));'
        */
        'secrets' => collect($platformKeys)
            ->mapWithKeys(fn (string $key) => [$key => (string) env('BILLING_ENTITLEMENT_SECRET_'.strtoupper($key), '')])
            ->all(),

        // Seconds a signed timestamp stays valid either side of "now".
        'tolerance' => 300,
    ],

    // Overdue reminders go to Dot.Notify's ingest API (server to server, queued). Both the URL and the
    // secret must be set or reminders are simply not sent. The secret is this app's
    // NOTIFY_INGEST_SECRET_BILLING value from Dot.Notify's .env.
    'notify' => [
        'url' => env('NOTIFY_INGEST_URL'),
        'secret' => env('NOTIFY_INGEST_SECRET'),
        'platform' => 'billing',
        'timeout' => 5,
    ],

    // A daily aggregate snapshot (counts and whole-cent sums only) is published to Dot.Analytics' S2S ingest
    // (POST /api/ingest/snapshots, queued). Both the URL and the secret must be set or nothing is sent. The
    // secret is this platform's ANALYTICS_INGEST_SECRET_BILLING value from Dot.Analytics' .env. The breaker
    // stops calling an Analytics that fails `breaker_threshold` times in a row, for `breaker_cooldown` seconds.
    'analytics' => [
        'url' => env('ANALYTICS_INGEST_URL'),
        'secret' => env('ANALYTICS_INGEST_SECRET'),
        'platform' => env('ANALYTICS_PLATFORM_KEY', 'billing'),
        'timeout' => 5,
        'breaker_threshold' => 5,
        'breaker_cooldown' => 300,
    ],

    // What the entitlements API reports for a company that has no live subscription.
    // These are placeholders until the owner decides the free tier: set them per environment.
    'free_tier' => [
        'seat_limit' => (int) env('BILLING_FREE_SEAT_LIMIT', 3),
        'storage_gb' => (int) env('BILLING_FREE_STORAGE_GB', 1),
        'features' => [],
    ],

];
