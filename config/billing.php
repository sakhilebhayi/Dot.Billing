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

    /*
    | Seminar ticket marketplace (see docs/seminar-payments.md). OFF unless BILLING_MARKETPLACE_ENABLED=true;
    | while off every marketplace endpoint answers 404 and the Paystack webhook ignores tickets entirely.
    | Only the platform key "infodot" may call it (its BILLING_ENTITLEMENT_SECRET_INFODOT signs the calls).
    |
    |  platform_percent  InfoDot's share of the ticket price, a whole number 0 to 50. The host bears Paystack's fee.
    |  currencies        comma separated list of currencies a ticket may be priced in
    |  min/max_amount    ticket price bounds in cents
    |  callback_url      where signed ticket.paid / ticket.refunded events are POSTed (https only)
    |  callback_secret   signs those events (HMAC-SHA256, same scheme as the entitlements calls)
    |  return_hosts      comma separated hosts a ticket's return_url may point at; empty = reject any return_url
    |  agreement_version the host term version that counts as current (a ticket needs an accepted current term)
    |  recovery_cap_percent  most of the host's share of a ticket taken to recover a lost chargeback, 0 to 100
    */
    'marketplace' => [
        'enabled' => (bool) env('BILLING_MARKETPLACE_ENABLED', false),
        'platform_percent' => env('BILLING_MARKETPLACE_PLATFORM_PERCENT', 10),
        'currencies' => array_values(array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', (string) env('BILLING_MARKETPLACE_CURRENCIES', 'ZAR'))))),
        'min_amount_cents' => (int) env('BILLING_MARKETPLACE_MIN_AMOUNT_CENTS', 500),
        'max_amount_cents' => (int) env('BILLING_MARKETPLACE_MAX_AMOUNT_CENTS', 5000000),
        'callback_url' => env('BILLING_MARKETPLACE_CALLBACK_URL'),
        'callback_secret' => env('BILLING_MARKETPLACE_CALLBACK_SECRET'),
        'callback_timeout' => 5,
        'return_hosts' => array_values(array_filter(array_map(fn ($h) => strtolower(trim($h)), explode(',', (string) env('BILLING_MARKETPLACE_RETURN_HOSTS', ''))))),
        'banks_cache_seconds' => 6 * 3600,
        // The version of the host term (docs/host-agreement-draft.md) a host must have accepted before a ticket can be
        // created for them. Change it when the wording changes: every host must then accept again.
        'agreement_version' => env('BILLING_MARKETPLACE_AGREEMENT_VERSION', 'v1'),
        // Most of the HOST'S share of a ticket (price minus platform fee) that may be taken as extra fee to recover a
        // lost chargeback, a whole percent 0 to 100. 0 turns recovery off.
        'recovery_cap_percent' => env('BILLING_MARKETPLACE_RECOVERY_CAP_PERCENT', 50),
    ],

    // What the entitlements API reports for a company that has no live subscription.
    // These are placeholders until the owner decides the free tier: set them per environment.
    'free_tier' => [
        'seat_limit' => (int) env('BILLING_FREE_SEAT_LIMIT', 3),
        'storage_gb' => (int) env('BILLING_FREE_STORAGE_GB', 1),
        'features' => [],
    ],

];
