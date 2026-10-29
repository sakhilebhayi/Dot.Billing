# Seminar ticket payments (R-13 marketplace)

InfoDot hosts can sell paid seminar tickets. Dot.Billing takes the attendee's payment through Paystack, splits it
between the host and InfoDot at the moment of payment, and tells InfoDot when a ticket is paid or refunded.

**The whole feature is OFF unless `BILLING_MARKETPLACE_ENABLED=true`.** While it is off every endpoint below
answers `404`, and the Paystack webhook ignores tickets entirely (a ticket reference is then treated like any
unknown reference). Invoice payments, entitlements and the existing webhook paths are not changed by this feature.

## The money split

Decided by the owner:

- InfoDot's share is **10% of the ticket price** (`BILLING_MARKETPLACE_PLATFORM_PERCENT`, a whole number 0 to 50).
- **The host bears Paystack's processing fee** (Paystack `bearer` = `subaccount`).
- Currency is **ZAR** only for now (`BILLING_MARKETPLACE_CURRENCIES`).

The platform fee is `round half up (amount_cents x percent / 100)`, worked out in whole cents when the ticket is
created and stored on the ticket. It is sent to Paystack as a **flat** `transaction_charge`, so InfoDot's share is
exactly that stored amount and not a percentage Paystack recomputes.

Worked example, a R200.00 ticket at 10%:

| | Amount |
|---|---|
| Attendee pays | R200.00 (`amount_cents` 20000) |
| InfoDot platform fee (10%) | R20.00 (`platform_fee_cents` 2000), settled to InfoDot's main Paystack account |
| Host share before Paystack's fee | R180.00 |
| Paystack's processing fee | charged to the **host's** share, at Paystack's rate for that transaction |
| Host receives | R180.00 minus Paystack's fee |

Rounding examples at 10%: R10.05 gives a R1.01 fee (100.5 rounds up); R10.04 gives R1.00; R9.99 gives R1.00.

## The flow

1. InfoDot calls `GET /api/payout-banks` to show the host a bank list.
2. InfoDot calls `POST /api/payout-accounts` once per host. Dot.Billing creates a Paystack **subaccount** and keeps
   only its code and the last four digits of the account. Posting the same `host_ref` again updates that subaccount.
3. For each sale InfoDot calls `POST /api/seminar-tickets` and sends the attendee to the returned
   `authorization_url` (Paystack's checkout).
4. When the attendee pays, Paystack calls `POST /webhooks/paystack` with `charge.success`. Dot.Billing **verifies the
   transaction with Paystack** (status success; reference, amount and currency equal what the ticket asked for) and
   only then marks the ticket `paid`. A replay does nothing.
5. Dot.Billing queues a signed `ticket.paid` callback to InfoDot, retried with backoff until InfoDot answers 2xx.
6. A refund is requested with `POST /api/seminar-tickets/{ticket_ref}/refund`. The ticket becomes `refunded` only
   when Paystack's `refund.processed` webhook arrives, then a signed `ticket.refunded` callback is queued.

The attendee's browser being sent back to InfoDot (`return_url`) is **UI only**. Nothing is marked paid because the
attendee came back; a ticket becomes paid solely through the verified webhook path. InfoDot can always reconcile
with `GET /api/seminar-tickets/{ticket_ref}`.

## Endpoints

All are under `routes/api.php`, throttled like the entitlements route (120 per minute), and server to server.

**Authentication.** The same per-platform HMAC scheme as `GET /api/entitlements/{uuid}`: headers
`X-InfoDot-Platform: infodot`, `X-InfoDot-Timestamp: <unix seconds>`, `X-InfoDot-Signature: sha256=<hex>` where the
hex is `HMAC-SHA256("{timestamp}.{raw body}", secret)` (the body is empty for a GET; for a POST the raw body is
signed). The secret is `BILLING_ENTITLEMENT_SECRET_INFODOT`. Only the platform key `infodot` may call these: any
other platform, a blank secret, a bad or stale (more than 5 minutes) signature all give the same generic
`401 {"error":"invalid_request"}`, and this check runs **before** anything is looked up.

Errors other than validation are `{"error": "<code>", "message": "..."}`. Validation failures are the standard
Laravel `422` with `errors`.

### `GET /api/payout-banks`
Paystack's bank list for South Africa (ZAR) as `[{"name": "...", "code": "..."}]`, sorted by name, inactive banks
left out, cached for 6 hours (a failed lookup is not cached). `503` if Paystack is unavailable.

### `POST /api/payout-accounts`
Body: `host_ref` (string, up to 64, letters digits `. _ : -`), `business_name` (up to 120), `settlement_bank` (a
Paystack bank code), `account_number` (5 to 20 digits, as a string).
Creates a Paystack subaccount with `percentage_charge` = the platform percent and stores
`payout_accounts` `{host_ref, paystack_subaccount_code (encrypted), bank_code, account_last4, business_name, status}`.
Returns `201` (or `200` on an update) `{id, status, last4, bank_code}`.

- One row per `host_ref`. Posting it again calls Paystack's update endpoint on the same subaccount; a second
  subaccount is never created.
- A `disabled` account stays disabled when re-posted. There is no endpoint that disables or re-enables one yet
  (an operator would change `status` in the database).
- Paystack refusing the bank details is `422 payout_account_rejected`.
- **The account number is never stored, returned or logged.** It is passed to Paystack inside a wrapper that prints
  as `[redacted]` (so it cannot appear in an exception trace) and is not written to the database, the audit trail,
  payment events, or any log line.

### `POST /api/seminar-tickets`
Body: `ticket_ref` (string, up to 64, InfoDot's idempotency key), `payout_account_id` (the `id` returned above),
`buyer_email`, `amount_cents` (integer), `currency`, `description` (up to 140), optional `return_url`.

- `amount_cents` must be between `BILLING_MARKETPLACE_MIN_AMOUNT_CENTS` (default 500) and
  `BILLING_MARKETPLACE_MAX_AMOUNT_CENTS` (default 5000000), inclusive. `currency` must be in the configured list.
  The payout account must exist and be `active` (`422 payout_account_unavailable` otherwise).
- `return_url` (optional, https only, up to 300 characters) is passed to Paystack as the transaction `callback_url` so
  the attendee lands back on InfoDot. Its host must be in `BILLING_MARKETPLACE_RETURN_HOSTS` (comma separated,
  exact host match, no wildcards). **The default is empty, which rejects every `return_url`**, so the feature never
  redirects to an unlisted host. An unlisted host, a credentials-in-URL trick or a backslash is a `422`.
- Creates a `ticket_payments` row `pending` with a generated unique `paystack_reference` (`dbt-...`) and initialises
  the Paystack transaction with the host's `subaccount`, `bearer` = `subaccount` and the flat `transaction_charge`.
- Returns `201` `{ticket_ref, reference, authorization_url, status}`.
- **Idempotent on `ticket_ref`.** The same request again returns `200` with the same result and makes no second
  Paystack call. Same `ticket_ref` with different fields is `409 ticket_conflict`. A paid or refunded `ticket_ref` is
  `409 already_paid`. A cancelled one is `409 ticket_cancelled` (use a new `ticket_ref`).
- If Paystack cannot initialise the transaction, nothing is left behind (`503` or `502`) and the call can simply be
  repeated.

### `GET /api/seminar-tickets/{ticket_ref}`
`{ticket_ref, status, amount_cents, platform_fee_cents, currency, paid_at, refunded_at}`, for reconciliation.
`status` is `pending`, `paid`, `refunded` or `failed`. `404 not_found` for an unknown ticket.

### `POST /api/seminar-tickets/{ticket_ref}/refund`
Only for a `paid` ticket. Asks Paystack to refund the whole transaction. Returns the status payload plus
`refund_requested: true`. The ticket stays `paid` until `refund.processed` arrives. Asking again changes nothing and
makes no second Paystack call. A `pending` or `failed` ticket is `409 ticket_not_refundable`; Paystack refusing is
`422 refund_rejected`; an already `refunded` ticket answers `200`.

### `POST /api/seminar-tickets/{ticket_ref}/cancel`
Only for a `pending` (unpaid) ticket. Marks it `failed` (reason `cancelled`) so it can never be paid later.
Cancelling twice is fine. A paid or refunded ticket is `409 ticket_not_cancellable`.

### Paystack webhook (existing route, extended)
`POST /webhooks/paystack` keeps its signature check and every existing behaviour for invoice payments. When the
marketplace is on and the reference belongs to a ticket:

- `charge.success`: verified with Paystack as above. Amount, currency or reference mismatch, or a verify result that
  is not `success`, is **held for an operator** (a `payment_events` row, status `unmatched`, with a reason) and the
  ticket is left alone. A `charge.success` for a **cancelled** ticket is held as `needs_review` / `ticket_cancelled`,
  logged as a warning and audited, not applied: the attendee has been charged and an operator must refund them.
  If Paystack cannot be reached to verify, the webhook answers `503` so Paystack delivers it again.
- `refund.processed`: a full refund of a paid ticket marks it `refunded`. A refund for any other amount, an unpaid
  ticket, or with a status other than `processed` is held for an operator (partial refunds are not handled).
- A reference that belongs to no ticket and no invoice is held as `unknown_reference`, as before.

## Callbacks to InfoDot

`POST {BILLING_MARKETPLACE_CALLBACK_URL}` (https only), JSON body, signed like the calls in, with
`BILLING_MARKETPLACE_CALLBACK_SECRET`: headers `X-InfoDot-Platform: billing`, `X-InfoDot-Timestamp`,
`X-InfoDot-Signature: sha256=HMAC-SHA256("{timestamp}.{raw body}")`, and `Idempotency-Key: {event}:{ticket_ref}`.
The signature is made at send time, so a late retry carries a fresh timestamp.

```
ticket.paid      {event, ticket_ref, reference, amount_cents, platform_fee_cents, currency, paid_at}
ticket.refunded  {event, ticket_ref, reference, amount_cents, currency, refunded_at}
```

Delivery is a queued job (`SendMarketplaceCallback`, needs `php artisan queue:work`). A 2xx is success. 5xx, 429,
408, 425, an unreachable receiver and also 401/403/404 (a receiver that is mis-set and may be fixed) are retried with
backoff of 1 min, 5 min, 15 min, 1 h, 2 h, 4 h, 12 h (8 tries), then the job lands in `failed_jobs` and an error is
logged. Other 4xx are not retried. **InfoDot must treat `(event, ticket_ref)` as idempotent**: a retry after a lost
response sends the same event again. If the callback URL or secret is missing the callback is not queued and an error
is logged; the ticket is still recorded as paid and `GET /api/seminar-tickets/{ticket_ref}` stays the source of truth.

## Configuration

| Env | Config key (`billing.marketplace.`) | Default | Meaning |
|---|---|---|---|
| `BILLING_MARKETPLACE_ENABLED` | `enabled` | `false` | Master switch. Off = every endpoint 404 and tickets ignored by the webhook |
| `BILLING_ENTITLEMENT_SECRET_INFODOT` | `billing.entitlements.secrets.infodot` | blank | Signs InfoDot's calls to Billing. Blank = InfoDot cannot call |
| `BILLING_MARKETPLACE_PLATFORM_PERCENT` | `platform_percent` | `10` | InfoDot's share, whole number 0 to 50. Anything else makes the endpoints answer `503 marketplace_misconfigured` |
| `BILLING_MARKETPLACE_CURRENCIES` | `currencies` | `ZAR` | Comma separated |
| `BILLING_MARKETPLACE_MIN_AMOUNT_CENTS` | `min_amount_cents` | `500` | Smallest ticket |
| `BILLING_MARKETPLACE_MAX_AMOUNT_CENTS` | `max_amount_cents` | `5000000` | Largest ticket |
| `BILLING_MARKETPLACE_CALLBACK_URL` | `callback_url` | blank | InfoDot's receiver, https |
| `BILLING_MARKETPLACE_CALLBACK_SECRET` | `callback_secret` | blank | Signs callbacks to InfoDot |
| `BILLING_MARKETPLACE_RETURN_HOSTS` | `return_hosts` | blank | Hosts a `return_url` may use. Blank rejects every `return_url` |
| `PAYSTACK_SECRET_KEY` and friends | `paystack.*` | | The existing Paystack settings, including the test-mode guard (a live key needs `PAYSTACK_ALLOW_LIVE=true` too) |

The two bounds are engineering defaults, not owner decisions. Changing `platform_percent` later does not change
tickets already created (their fee is stored), and existing subaccounts keep the percentage they were created with
(harmless, because every ticket sends a flat charge that overrides it).

## Audit trail

`payout_account.created`, `payout_account.updated`, `ticket.created`, `ticket.paid`, `ticket.refund_requested`,
`ticket.refunded`, `ticket.cancelled` and `ticket.held` are written to `billing_audit_events` with no team (the
`team_id` column became nullable for this). Metadata never contains an account number, a subaccount code or a key.

## What is NOT handled

- **VAT / tax invoicing** for InfoDot's platform fee or for the host's ticket sale. No invoice or receipt is issued.
- **Host KYC** beyond whatever Paystack itself checks when it creates the subaccount.
- **Chargebacks and disputes.** A dispute event from Paystack is not read; an operator has to deal with it in Paystack.
- **Payout timing.** Paystack controls when the host and InfoDot are settled; Billing does not track settlements.
- **Partial refunds.** Refund always asks for the full amount; a partial refund made in Paystack is held for an operator.
- Disabling or re-enabling a payout account through the API, expiring an unpaid ticket automatically (a host cancels
  it), and a command to re-send callbacks that failed for good.

## Owner decisions still open

1. **Refund policy for attendee-initiated cancellations.** Today a refund happens only when InfoDot asks for it.
   Whether an attendee may cancel, by what date, and who decides, is not defined.
2. **Who is the merchant of record** for a ticket: the host, or InfoDot as the marketplace. This drives refunds,
   disputes, receipts and who is liable if the host does not deliver.
3. **VAT**: whether the platform fee carries VAT, whether hosts must show VAT on tickets, and who issues the document.
