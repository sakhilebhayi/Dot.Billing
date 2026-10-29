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
   Before the host can sell, InfoDot shows them the host term ([host-agreement-draft.md](host-agreement-draft.md)) and records the
   acceptance with `POST /api/payout-accounts/{id}/agreement` (see "Chargeback recovery" below).
3. For each sale InfoDot calls `POST /api/seminar-tickets` and sends the attendee to the returned
   `authorization_url` (Paystack's checkout).
4. When the attendee pays, Paystack calls `POST /webhooks/paystack` with `charge.success`. Dot.Billing **verifies the
   transaction with Paystack** (status success; reference, amount and currency equal what the ticket asked for) and
   only then marks the ticket `paid`. A replay does nothing.
5. Dot.Billing queues a signed `ticket.paid` callback to InfoDot, retried with backoff until InfoDot answers 2xx.
6. A refund (whole or partial) is requested with `POST /api/seminar-tickets/{ticket_ref}/refund`. It is applied only
   when Paystack's `refund.processed` webhook arrives (`partially_refunded`, or `refunded` once the total equals the
   ticket amount), then a signed `ticket.refunded` callback is queued.
7. If the attendee disputes the charge with their bank, Paystack's `charge.dispute.create` / `charge.dispute.resolve`
   are recorded against the ticket and InfoDot is told (`ticket.disputed`, `ticket.dispute_resolved`). Billing only
   records disputes; it never submits evidence or resolves one. A person does that in the Paystack dashboard.

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
  The payout account must exist and be `active` (`422 payout_account_unavailable` otherwise), and its host must have accepted the
  **current** version of the host agreement (`422 agreement_required` otherwise; nothing is created and Paystack is not called).
- `return_url` (optional, https only, up to 300 characters) is passed to Paystack as the transaction `callback_url` so
  the attendee lands back on InfoDot. Its host must be in `BILLING_MARKETPLACE_RETURN_HOSTS` (comma separated,
  exact host match, no wildcards). **The default is empty, which rejects every `return_url`**, so the feature never
  redirects to an unlisted host. An unlisted host, a credentials-in-URL trick or a backslash is a `422`.
- Creates a `ticket_payments` row `pending` with a generated unique `paystack_reference` (`dbt-...`) and initialises
  the Paystack transaction with the host's `subaccount`, `bearer` = `subaccount` and the flat `transaction_charge`
  (the platform fee, plus `recovery_cents` when the host owes a lost chargeback; see "Chargeback recovery").
- Returns `201` `{ticket_ref, reference, authorization_url, status}`.
- **Idempotent on `ticket_ref`.** The same request again returns `200` with the same result and makes no second
  Paystack call. Same `ticket_ref` with different fields is `409 ticket_conflict`. A paid or refunded `ticket_ref` is
  `409 already_paid`. A cancelled one is `409 ticket_cancelled` (use a new `ticket_ref`).
- If Paystack cannot initialise the transaction, nothing is left behind (`503` or `502`) and the call can simply be
  repeated.

### `GET /api/seminar-tickets/{ticket_ref}`
`{ticket_ref, status, amount_cents, platform_fee_cents, recovery_cents, currency, refunded_cents, dispute_status, paid_at, refunded_at}`,
for reconciliation. `amount_cents` is always the original ticket amount. `recovery_cents` is the extra fee asked for on this
ticket to recover a lost chargeback (0 for almost every ticket); InfoDot's whole take on the ticket is `platform_fee_cents + recovery_cents`.

| `status` | Meaning |
|---|---|
| `pending` | created, not paid |
| `paid` | paid, nothing refunded, no open dispute |
| `partially_refunded` | `0 < refunded_cents < amount_cents` |
| `refunded` | `refunded_cents == amount_cents` |
| `failed` | cancelled while unpaid |
| `disputed` | a Paystack dispute is open (or resolved with an outcome nobody could classify): the host cannot refund |
| `chargeback` | a dispute was lost |

`dispute_status` is `open` or `resolved` for the latest dispute on the ticket, `null` if it never had one.
`refunded_at` is the time of the most recent processed refund. `404 not_found` for an unknown ticket.

### `POST /api/seminar-tickets/{ticket_ref}/refund`
For a `paid` or `partially_refunded` ticket. Optional JSON body `{amount_cents: int > 0, refund_key: string up to 64}`.

- **No amount**: refund the whole remaining amount. The refund key is then `full` (or the `refund_key` you send).
- **With `amount_cents`**: `refund_key` is **required** and is the idempotency key. The same key and amount again returns the same
  refund and makes **no second Paystack call**; the same key with a different amount is `409 refund_key_conflict`. A refund that
  Paystack reported as failed may be asked for again under its key.
- An amount above what is still refundable is `422 refund_exceeds_remaining`. "Still refundable" is the ticket amount minus
  `refunded_cents` minus refunds still waiting for Paystack's confirmation, so two in-flight requests cannot together over-refund.
- Billing passes the amount (and currency) to Paystack's refund API; when the whole untouched ticket is refunded it sends no amount.
- Nothing is refunded by this call. `refunded_cents` and the status move only when Paystack's `refund.processed` arrives. The answer is
  the status payload plus `refund_requested` (a refund is waiting for Paystack) and `refund` `{refund_key, amount_cents, status}`
  with status `pending`, `processed` or `failed`.
- A **disputed** ticket is `409 ticket_disputed`; a `chargeback`, `pending` or `failed` ticket is `409 ticket_not_refundable`.
  Paystack refusing is `422 refund_rejected`. A fully refunded ticket asked for "everything" with no key answers `200` unchanged.
- Data: `ticket_payments.refunded_cents` and a `ticket_refunds` row per refund (`ticket_payment_id`, `refund_key` unique per ticket,
  `amount_cents`, `status`, `paystack_refund_id`, `processed_at`).

**Not verified: how Paystack apportions a refund between the host's subaccount and the platform on a split transaction.** Whether the
host alone bears a partial refund, whether InfoDot's flat fee is returned pro rata, and which balance is debited, has not been checked
against Paystack and this code does not try to decide it. **Do one small live test (a small ticket, a small partial refund) and look at
both balances before this is used with real money.**

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
- `refund.processed`: matched to our `ticket_refunds` row by Paystack's refund id (or, failing that, the one pending refund of that
  amount). A refund started in the Paystack dashboard has no row and gets one (key `paystack-<id>`). It is applied if its own status is
  `processed`, its currency is the ticket's and it does not exceed what is still refundable; otherwise it is held for an operator
  (`refund_amount_mismatch`, `refund_exceeds_remaining`, `refund_currency_mismatch`, `refund_not_processed`, `refund_for_unpaid_ticket`,
  `refund_ambiguous`). A replay is a no-op.
- `refund.failed`: marks the matching pending refund `failed`, which frees that amount and lets the same `refund_key` be asked for again.
  Anything it cannot match is ignored. InfoDot is not called back for a failed refund.
- `charge.dispute.create` / `charge.dispute.resolve` (below).
- A reference that belongs to no ticket and no invoice is held as `unknown_reference`, as before.

## Callbacks to InfoDot

`POST {BILLING_MARKETPLACE_CALLBACK_URL}` (https only), JSON body, signed like the calls in, with
`BILLING_MARKETPLACE_CALLBACK_SECRET`: headers `X-InfoDot-Platform: billing`, `X-InfoDot-Timestamp`,
`X-InfoDot-Signature: sha256=HMAC-SHA256("{timestamp}.{raw body}")`, and `Idempotency-Key: {event}:{ticket_ref}`.
The signature is made at send time, so a late retry carries a fresh timestamp.

```
ticket.paid             {event, ticket_ref, reference, amount_cents, platform_fee_cents, currency, paid_at}
ticket.refunded         {event, ticket_ref, reference, amount_cents, currency, refunded_at,
                         refund_amount_cents, refunded_total_cents, refund_key}
ticket.disputed         {event, ticket_ref, reference, amount_cents, currency, opened_at}
ticket.dispute_resolved {event, ticket_ref, reference, amount_cents, currency, outcome, resolved_at}
```

```
host_debt.opened        {event, ticket_ref, debt_id, payout_account_id, host_ref, currency, amount_cents, recovered_cents, remaining_cents, opened_at}
host_debt.cleared       {... same fields ..., cleared_at}
host_debt.reopened      {... same fields ..., reopened_at}      (a refund gave recovery back to a cleared debt)
host_debt.written_off   {... same fields ..., written_off_at, written_off_cents, written_off_by_ref, note}
```

For the `host_debt.*` events `ticket_ref` is the ticket whose chargeback made the debt (one debt per ticket), and the `Idempotency-Key` is
`{event}:{ticket_ref}:{opened_at|cleared_at|reopened_at|written_off_at}`. `host_debt.reopened` is not in the owner's brief: it exists because a
refund can put a cleared debt back to open, and InfoDot would otherwise keep showing it as cleared.

`amount_cents` is always the ORIGINAL ticket amount. `ticket.paid` still carries the base `platform_fee_cents` only; read
`recovery_cents` from the status endpoint. `ticket.refunded` is sent once per processed refund (a ticket can have several
partial refunds); `outcome` is `won`, `lost` or `other`.

Delivery is a queued job (`SendMarketplaceCallback`, needs `php artisan queue:work`). A 2xx is success. 5xx, 429,
408, 425, an unreachable receiver and also 401/403/404 (a receiver that is mis-set and may be fixed) are retried with
backoff of 1 min, 5 min, 15 min, 1 h, 2 h, 4 h, 12 h (8 tries), then the job lands in `failed_jobs` and an error is
logged. Other 4xx are not retried. **InfoDot must treat events as idempotent**: a retry after a lost
response sends the same event again. De-duplicate on `(event, ticket_ref)`, and for `ticket.refunded` on `(event, ticket_ref, refund_key)`
because one ticket can have several refunds (`ticket.disputed` and `ticket.dispute_resolved` can repeat if a ticket is disputed twice, so
add `opened_at` / `resolved_at`). The `Idempotency-Key` header carries exactly that: `{event}:{ticket_ref}` plus the `refund_key`,
`opened_at` or `resolved_at` where there is one. If the callback URL or secret is missing the callback is not queued and an error
is logged; the ticket is still recorded as paid and `GET /api/seminar-tickets/{ticket_ref}` stays the source of truth.

## Disputes and chargebacks

Paystack's `charge.dispute.create` and `charge.dispute.resolve` are recorded when the transaction reference belongs to a ticket (marketplace
on). For every other reference they are ignored, exactly as before. **Billing records only. It never submits evidence, accepts or
declines a dispute through the API; a person does that in the Paystack dashboard.**

- `charge.dispute.create`: a `ticket_disputes` row (`ticket_payment_id`, `paystack_dispute_id` unique, `status` `open`, `opened_at`). A
  `paid` or `partially_refunded` ticket becomes `disputed` (a refund request is then `409 ticket_disputed`). An audit entry is written and
  `ticket.disputed` is queued. A dispute on a ticket that was never paid is held for an operator. A replay is a no-op.
- `charge.dispute.resolve`: the dispute becomes `resolved` with `resolution` `won`, `lost` or `other` (Paystack's raw text is kept in
  `paystack_resolution`) and `ticket.dispute_resolved` is queued.
  - **won** returns a `disputed` ticket to its resting status (`paid`, `partially_refunded` or `refunded`, from `refunded_cents`).
  - **lost** sets `chargeback` and opens a host debt (see "Chargeback recovery").
  - **other** (anything not recognised) keeps the ticket `disputed`, so it still cannot be refunded, and is held for an operator
    (`dispute_outcome_unknown`). This is a deliberate departure from "resolved means unblocked", to avoid guessing on money.
- The mapping `declined` -> won and `merchant-accepted` -> lost comes from Paystack's documentation and has **not been checked against a
  real dispute**. A refund that was already in flight when a dispute opened is still counted when it arrives, without changing the status.
- A resolve for a dispute we never saw opened is recorded as already resolved. A dispute on an already refunded ticket is recorded and
  reported but leaves the status `refunded` until it is resolved.
- Evidence deadlines, the money Paystack actually debits for a lost dispute and any dispute fee are not tracked. The host debt is
  worked out from the ticket (see below), not from what Paystack debited.

## Chargeback recovery (host agreement and host debts)

Decided by the owner: a host accepts a term first; until a debt is cleared, up to 50% of the **host's share** of each new ticket goes to
InfoDot as a larger fee on that ticket; nothing is taken without the accepted term; a moderator can write a debt off. The wording of the
term is a DRAFT for the owner's adviser: [host-agreement-draft.md](host-agreement-draft.md). Like the rest of this feature it does nothing
unless `BILLING_MARKETPLACE_ENABLED=true`.

### The agreement

`POST /api/payout-accounts/{id}/agreement` with `{version, accepted_by_ref}` (`version` up to 32 letters, digits, `. _ -`;
`accepted_by_ref` is InfoDot's reference for the person who accepted, up to 64). Same signed InfoDot-only call as every other endpoint.
Stored as `host_agreements` `(payout_account_id, version, accepted_at, accepted_by_ref)`, unique per account and version.

- `201` the first time, `200` for a repeat of the same version. **The first acceptance stands**: a repeat changes nothing, even with a different
  `accepted_by_ref`, and writes no second audit entry.
- The answer is `{payout_account_id, version, accepted_at, accepted_by_ref, current_version, current}`. Accepting an older version is recorded
  (`current: false`) but does not count.
- `404 not_found` for an unknown account; the standard `422` for bad input.
- **While the marketplace is on, a ticket cannot be created for an account that has no acceptance of `billing.marketplace.agreement_version`:**
  `422 agreement_required`. Changing the configured version therefore asks every host to accept again. A ticket that already exists is still
  answered by the idempotent repeat.

### When a debt is opened, and for how much

A Paystack dispute resolved **lost** (the existing `charge.dispute.resolve` path, ticket status `chargeback`) opens one `host_debts` row for
that ticket, in the same transaction, once (`ticket_payment_id` is unique, so a replay or a second dispute on the ticket opens nothing more).
A **won** or unclassified (`other`) dispute opens nothing.

    debt = ticket amount - platform fee - what was already refunded to the attendee        (never below 0)

Why: the attendee's bank takes the chargeback back from InfoDot's Paystack account. The host was paid their share through the split and InfoDot kept
only the platform fee, so what InfoDot lost is the host share. A refund already returned part of the money to the attendee, so that part is not at stake
twice. Paystack's own processing fee and any dispute fee are not counted (the host bore the former; the latter is not tracked). If the result is 0
(for example a fully refunded ticket) no debt is opened and `host_debt.not_opened` is audited. The debt is taken whether or not the host has accepted the
term, because it is a fact about money lost; **recovery** needs the term.

The extra fee taken from the lost ticket itself for an *earlier* debt is **not** subtracted: the chargeback takes that money back too, so it is part of
this loss. The earlier debt stays cleared.

### The extra fee on a new ticket

When a ticket is created for a host with an accepted current term and open debt in the ticket's currency:

    host share      = amount - platform fee
    cap             = floor(host share x recovery_cap_percent / 100)
    still owed      = sum of (amount - recovered) over the host's open debts
                      - recovery already promised on the host's unpaid (pending) tickets
    extra fee       = min(still owed, cap)
    transaction_charge sent to Paystack = platform fee + extra fee   (one flat integer)

The extra fee is stored as `ticket_payments.recovery_cents` and returned by `GET /api/seminar-tickets/{ticket_ref}`. Because the cap is at most 100% of the
host share, the total charge never exceeds the ticket amount; the default 50% leaves the host at least half their share before Paystack's fee.
The debt rows are read `FOR UPDATE` inside the transaction that creates the ticket, and unpaid tickets' promised recovery is subtracted, so two tickets
created at the same moment cannot together promise more than is owed. (That lock is held while the Paystack initialise call runs; it only exists for a
host that has open debt.)

**Worked example.** A R200.00 ticket, 10% platform fee, host owes R100.00, cap 50%:

| | Cents | Rand |
|---|---:|---:|
| Attendee pays | 20000 | R200.00 |
| Platform fee (10%) | 2000 | R20.00 |
| Host share before recovery | 18000 | R180.00 |
| Cap (50% of host share) | 9000 | R90.00 |
| Host owes | 10000 | R100.00 |
| Extra fee = min(owed, cap) | 9000 | R90.00 |
| `transaction_charge` sent to Paystack = 2000 + 9000 | 11000 | R110.00 |
| InfoDot receives on this ticket | 11000 | R110.00 |
| Host receives (before Paystack's fee) | 9000 | R90.00 |
| Debt after the ticket is paid | 1000 | R10.00 still owed |

A ticket smaller than the debt: a R50.00 ticket against a R1,000 debt has platform fee R5.00, host share R45.00, extra fee R22.50. Rounding: the cap is
rounded down (a host share of R9.03 gives a cap of 451 cents, not 452).

### Credited only when the ticket is verified paid

In the same transaction that marks a ticket `paid` (after Paystack's verify), its `recovery_cents` is credited to the host's open debts, oldest first,
never above what each still owes, and each credit is a `host_debt_recoveries` row unique per (debt, ticket). A webhook replay returns `replayed` before it
gets there, so it is credited once. A debt reaching zero remaining becomes `cleared` and InfoDot gets `host_debt.cleared`.

If the debt shrank after the ticket was priced (a moderator wrote it off while the ticket was unpaid), the larger fee was already taken by Paystack and
there is nothing left to credit it to: the amount is audited as `host_debt.recovery_unapplied` and logged as a warning, and **a person must decide**
whether to credit it back to the host. It is not guessed at.

### Cancelled, failed and refunded tickets

- A ticket that is **cancelled or failed** was never credited, so the debt is unchanged, and it stops counting as "promised" immediately (only
  `pending` tickets reserve debt). Its `recovery_cents` stays on the row as history.
- A **refund** gives recovery back in proportion to the refunded share: total given back = credited x refunded / ticket amount, rounded down, and all of
  it once the ticket is fully refunded. Several partial refunds therefore never give back more than was credited. A cleared debt that falls below its amount
  is `open` again (`host_debt.reopened`); a written-off debt is left as it is. A refund on a ticket that carried no recovery touches no debt.
- A ticket that is later lost to a chargeback keeps whatever recovery was credited (see above).

### Write-off and listing

- `GET /api/payout-accounts/{id}/debts` returns `{payout_account_id, agreement: {current_version, accepted}, open_remaining_cents,
  in_flight_recovery_cents, debts: [{id, ticket_ref, currency, amount_cents, recovered_cents, remaining_cents, written_off_cents, status, opened_at,
  cleared_at, written_off_at, written_off_by_ref, note}]}`. `remaining_cents` is 0 unless the debt is `open`. `404` for an unknown account.
- `POST /api/host-debts/{id}/write-off` with `{note (up to 500), written_off_by_ref (up to 64)}`: the moderator's action, started by InfoDot, which
  records who. Only an `open` debt (`409 debt_not_open` otherwise); a repeat on a written-off debt answers `200` unchanged and sends no second event.
  Nothing more is taken for it afterwards. `host_debt.written_off` goes to InfoDot.

All four `host_debt.*` events use the signed callback described above.

### Unverified Paystack behaviour (do one small live test first)

None of the following has been checked against Paystack, and the code does not try to decide it:

- That a flat `transaction_charge` larger than the platform fee is accepted on a split transaction, and that it all settles to InfoDot's main account.
- That, with `bearer: subaccount`, Paystack's processing fee is still taken from the host's remaining share when the host's share is reduced by the extra fee.
  (At the default 50% cap there is room; a cap near 100% could leave too little, and what Paystack does then is unknown.)
- How a refund is split between host and InfoDot (already flagged above). The proportional reversal assumes InfoDot returns its extra fee pro rata on a
  refund; if Paystack takes refunds only from the host, the reversal credits the host back money InfoDot did not return.
- How much Paystack actually debits for a lost dispute (the transaction amount, less or more, plus a dispute fee) and from which balance. The debt is
  computed from the ticket, so it can differ from the real debit.
- The `merchant-accepted` to lost mapping (already flagged above).

### What is NOT handled

- **Legal basis.** The term is an unreviewed draft. Recovery takes money from a host's ticket sales under it; do not enable it with real money until the
  owner's adviser has reviewed and approved the wording, including POPIA, consumer-protection and any required notice period.
- **Notifying the host.** Billing does not tell a host about a debt or a larger fee; InfoDot should, through the events above.
- **Expiring unpaid tickets.** An unpaid ticket's promised recovery stays reserved until the ticket is paid or cancelled, because unpaid tickets never expire
  (see above). An abandoned ticket therefore blocks recovery of that amount until someone cancels it.
- **Debts in a different currency** from the ticket are never used (only ZAR is enabled today).
- **Hosts who never sell again** are never recovered from; the debt stays open until a moderator writes it off.
- **Interest, fees and collection** outside the larger fee on later tickets.
- Moving a debt between payout accounts if a host is re-registered under a new `host_ref`.

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
| `BILLING_MARKETPLACE_AGREEMENT_VERSION` | `agreement_version` | `v1` | The host-term version that counts as current (1 to 32 letters, digits, `. _ -`). Anything else makes the endpoints answer `503 marketplace_misconfigured`. Change it when the wording changes: every host must accept again |
| `BILLING_MARKETPLACE_RECOVERY_CAP_PERCENT` | `recovery_cap_percent` | `50` | Most of the **host's share** of one ticket taken to recover a lost chargeback, a whole number 0 to 100 (0 = recovery off). Anything else makes the endpoints answer `503 marketplace_misconfigured` |
| `PAYSTACK_SECRET_KEY` and friends | `paystack.*` | | The existing Paystack settings, including the test-mode guard (a live key needs `PAYSTACK_ALLOW_LIVE=true` too) |

The two bounds are engineering defaults, not owner decisions. Changing `platform_percent` later does not change
tickets already created (their fee is stored), and existing subaccounts keep the percentage they were created with
(harmless, because every ticket sends a flat charge that overrides it).

## Audit trail

`payout_account.created`, `payout_account.updated`, `ticket.created`, `ticket.paid`, `ticket.refund_requested`,
`ticket.refunded` (one per processed refund), `ticket.refund_failed`, `ticket.cancelled`, `ticket.disputed`, `ticket.dispute_resolved`, `ticket.held`, `host_agreement.accepted`, `host_debt.opened`, `host_debt.not_opened`, `host_debt.recovered`, `host_debt.cleared`, `host_debt.recovery_reversed`, `host_debt.recovery_unapplied` and `host_debt.written_off` are written to `billing_audit_events` with no team (the
`team_id` column became nullable for this). Metadata never contains an account number, a subaccount code or a key.

## What is NOT handled

- **VAT / tax invoicing** for InfoDot's platform fee or for the host's ticket sale. No invoice or receipt is issued.
- **Host KYC** beyond whatever Paystack itself checks when it creates the subaccount.
- **Answering disputes.** Disputes are recorded and reported (above) but evidence, deadlines and dispute fees are handled by a person in
  the Paystack dashboard; Billing does nothing to a dispute.
- **Payout timing.** Paystack controls when the host and InfoDot are settled; Billing does not track settlements.
- How Paystack splits a refund between the host and InfoDot (see the refund section: not verified).
- Whether the refunded amount in a `refund.processed` webhook is in cents: assumed, and a wrong guess shows up as refunds held for an operator.
- Chargeback recovery has its own list above.
- Disabling or re-enabling a payout account through the API, expiring an unpaid ticket automatically (a host cancels
  it), and a command to re-send callbacks that failed for good.

## Owner decisions still open

1. **Refund policy for attendee-initiated cancellations.** Today a refund happens only when InfoDot asks for it.
   Whether an attendee may cancel, by what date, and who decides, is not defined.
2. **Who is the merchant of record** for a ticket: the host, or InfoDot as the marketplace. This drives refunds,
   disputes, receipts and who is liable if the host does not deliver.
3. **VAT**: whether the platform fee carries VAT, whether hosts must show VAT on tickets, and who issues the document.
4. **Recovery details I chose, which the owner must confirm.** The debt is the host share still outstanding (price minus platform fee minus
   refunds); a refund gives recovery back pro rata; recovery needs the host to have accepted the *current* version; the cap is a percent of the host
   share, default 50; a host who stops selling is never chased. And the wording of the term itself, which needs the owner's adviser.
