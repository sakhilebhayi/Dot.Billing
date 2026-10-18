<?php

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

    // Printed on the invoice. Anything left blank is simply not printed; nothing is invented.
    'issuer' => [
        'name' => env('BILLING_ISSUER_NAME'),
        'address' => array_values(array_filter(array_map('trim', explode('|', (string) env('BILLING_ISSUER_ADDRESS', ''))))),
        'tax_number' => env('BILLING_ISSUER_TAX_NUMBER'),
        'email' => env('BILLING_ISSUER_EMAIL'),
    ],

    // What the entitlements API reports for a company that has no live subscription.
    // These are placeholders until the owner decides the free tier: set them per environment.
    'free_tier' => [
        'seat_limit' => (int) env('BILLING_FREE_SEAT_LIMIT', 3),
        'storage_gb' => (int) env('BILLING_FREE_STORAGE_GB', 1),
        'features' => [],
    ],

];
