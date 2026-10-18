<div align="center">

<img src="public/images/logo.png" alt="Dot.Billing" width="320" />

<br /><br />

**Manage plans, track invoices, and analyse spend across the Dot ecosystem.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the [InfoDot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `billing.infodot.app`

</div>

---

## What is Dot.Billing?

Dot.Billing is the subscription and billing management platform in the InfoDot ecosystem. Teams track their active plans, receive and pay invoices, monitor cross-platform usage, and get AI-powered spend optimisation recommendations — all in one billing intelligence hub.

## Core Features

- Subscriptions: plans, monthly/annual billing, trials, plan changes at renewal, cancel at period end and resume
- A plan catalogue that platform operators manage (create, edit, archive; never delete)
- An invoice engine: per-team sequential invoice numbers, snapshot line items, configurable tax and terms, a print-friendly invoice view
- Money in integer minor units (cents); totals are always per currency, never summed across currencies
- Invoice dashboard and search, and a per-invoice detail page
- Usage records per platform (`billing_usage_records`)
- Budget alerts (`billing_alerts`) and a human-gated dunning queue for operators
- In-app notification bell (Laravel's `database` notification channel) for billing events
- Dark / light mode toggle (Tailwind class-based strategy, persisted per browser)
- AI spend analysis (Claude), shown only when the call really worked
- Ecosystem SSO from InfoDot hub

> **No payment provider is integrated, and none has been chosen.** Nothing here charges a card or an account.
> Invoices are raised by the app; money is recorded against them. The `stripe_*` columns are unused references.

## How billing works

**Money.** Every amount is stored as integer minor units in `*_cents` columns (`App\Support\Money`).
The original `decimal(10,2)` columns remain as a mirror the models keep in step, so a rollback or an old reader
still sees correct numbers; new code reads and writes cents. Every amount carries its own currency and the
dashboard lists totals per currency. All supported currencies are assumed to have two minor-unit digits.

**Subscriptions** (`App\Services\Billing\SubscriptionService`). A team has at most one live subscription
(`active`, `trialing` or `past_due`): the service checks, and a partial unique index enforces it on SQLite and
PostgreSQL (MySQL has no partial indexes; there the service check is the only guard). Only team owners and
Jetstream admins may subscribe, change plan, cancel or resume (the `manage-billing` gate). A plan change on a
running subscription takes effect at the next renewal, so there is no proration. A team gets one trial.

**The clock** (both scheduled daily, both idempotent, both safe to re-run):

| Command | Does |
|---|---|
| `billing:advance-subscriptions` (01:00) | ends expired trials, rolls ended periods forward (applying a queued plan change) and invoices the new period, ends subscriptions scheduled to cancel, keeps `past_due` accurate |
| `billing:generate-invoices` (01:30) | raises the invoice for any live paid subscription whose current period has none (catch-up and standalone use) |

Billing is in advance: one invoice per period, raised when the period starts. Free plans and trials are never
invoiced. Nothing is charged automatically, and nothing is ever cancelled for non-payment automatically:
`past_due` only records that an open invoice is overdue.

**Invoices.** Numbers are per team and per year (`INV-{YYYY}-{seq}`, configurable), allocated under a row lock
inside the invoice transaction. Status runs `draft` to `open` to `paid` or `void` (`uncollectible` exists on
legacy rows). Due date is issue date plus the configured terms. Line items are snapshots. See `config/billing.php`
for number format, terms, tax rate (a percentage, default 0), auto-finalize, issuer details for the print view,
and the free-tier limits.

**Roles.** Owner and `admin` manage billing. `editor` and the read-only `viewer` role can read invoices and the
subscription state. A draft invoice is visible only to managers.

**Recording payments** (`App\Services\Billing\RecordPayment`). The one entry for money arriving. It applies a
payment to an invoice inside a transaction with the invoice locked: partial payments are allowed, an overpayment
or a wrong currency is rejected, only an `open` invoice can be paid, and the invoice becomes `paid` when the
balance reaches zero. Every fact is stored in `payment_events` (provider and external id nullable;
`unique(provider, external_id)` so a replayed provider event is a no-op, and the same id with different details
is a `payment_conflict`). The stored payload has secrets scrubbed. Owners and admins can use "Record a manual
payment" on an open invoice; it is logged with their name. Only payments exist: refunds are not modelled.

### Where a payment provider plugs in

No provider SDK, webhook endpoint or card handling exists in this app, by design. When a provider is chosen,
its adapter is a thin layer that (1) exposes its own webhook route and verifies that provider's signature,
(2) maps the event to an open invoice (by invoice number or metadata), and (3) calls
`RecordPayment::apply($invoice, new Money($cents, $currency), provider: 'name', externalId: $eventId, method:
'card', payload: $event)`. Idempotency, partial payments, status changes and the audit row come with that call.
Subscription renewals need no provider: `billing:advance-subscriptions` and `billing:generate-invoices` already
raise the invoices an adapter would collect. The unused `stripe_*` columns can be dropped or reused then.

### Entitlements API

`GET /api/entitlements/{company_uuid}` tells another Dot app what a company is on. `company_uuid` is InfoDot's
company id, matched to `teams.infodot_team_uuid`. Server to server only, signed like InfoDot's other calls:

| Header | Value |
|---|---|
| `X-InfoDot-Platform` | the calling platform's key, e.g. `files` |
| `X-InfoDot-Timestamp` | unix time, accepted within 5 minutes either way |
| `X-InfoDot-Signature` | `sha256=` + HMAC-SHA256 hex of `"{timestamp}.{raw body}"` (the body of a GET is empty) |

Each platform has its own secret in `BILLING_ENTITLEMENT_SECRET_<KEY>` (a blank secret means that platform cannot
call). Bad or stale signatures, unknown platforms and blank secrets all return the same `401`; an unknown company
is a generic `404`, and only after authentication. The response carries `status` (`active`, `trialing`,
`past_due`, `canceled`, `none`), the plan, `seat_limit` and `storage_gb` (`null` = unlimited), `features`,
`period_end` and `cancel_at_period_end`, with an `ETag`. A company with no live subscription gets the free-tier
limits from `config/billing.php` (placeholders: set `BILLING_FREE_SEAT_LIMIT` and `BILLING_FREE_STORAGE_GB`).
`past_due` keeps the plan's limits; whether to restrict is the consuming app's decision. The signature does not
cover the URL (the scheme's known limit). Operators can check any company at `/operator/entitlements`.

### Dunning, reminders and safety

`billing:scan-alerts` (every 15 minutes) opens a **dunning case** for an invoice that is open and past its due
date, and for a failed payment, for a platform operator to review at `/operator/dunning-cases`. It never suspends
or cancels anything. The operator can:

- **Grant grace**: the case moves to `grace` with an end date and the invoice stops counting as overdue until then.
  The invoice's real due date is not rewritten. The scanner reopens the case only after the grace ends and the
  invoice is still unpaid.
- **Cancel the subscription**: ends the one subscription the case's invoice was raised for. If that cannot be
  determined (a legacy invoice with no link and no single live subscription) nothing is cancelled and the
  operator is told.
- **Dismiss**: closes the case, changes nothing else.

Each case is unique per overdue invoice and per failed payment (a partial unique index on SQLite and PostgreSQL,
the scanner's check elsewhere). Paying an invoice in full resolves its open case.

**Overdue reminders** go to Dot.Notify's ingest API (`POST /api/ingest/events`, event `invoice.overdue`, to the
team owner) through `App\Services\Notify\NotifyClient`: queued (`App\Jobs\SendNotifyEvent`, so it needs a queue
worker, `php artisan queue:work`), 5 second timeout, retried with backoff while Notify is down, never throws into
the caller, and disabled when `NOTIFY_INGEST_URL` or `NOTIFY_INGEST_SECRET` is blank. The request is signed with
HMAC-SHA256 over `"{timestamp}.{raw body}"`; the idempotency key is event + invoice id + date. The team must be
linked to InfoDot (`teams.infodot_team_uuid`) or there is nobody to address and the reminder is skipped.

**Metrics for Dot.Analytics.** Once a day (03:00 UTC, `billing:publish-metrics`) this app queues one signed snapshot
per company to Dot.Analytics' server-to-server ingest (`POST /api/ingest/snapshots`) through
`App\Services\Analytics\AnalyticsClient` and `App\Jobs\SendAnalyticsSnapshot`. A company is a non-personal team
linked to InfoDot (`teams.infodot_team_uuid`); personal and unlinked teams are skipped.

What is sent, and nothing else (Dot.Analytics' `billing` catalogue; counts and whole-cent sums only, never a name,
email, invoice number or row):

| Key | Meaning |
|---|---|
| `mrr_cents` | monthly recurring revenue: active and past_due subscriptions at their plan price, annual plans divided by 12 and rounded half up once. Trials are not revenue and are not counted |
| `open_invoices` | invoices with status `open` |
| `overdue_invoices` | open invoices past their due date and outside a grace period (the app's own overdue rule) |
| `overdue_cents` | the balance still owed on those invoices |
| `currency` | ISO 4217 code of the two `*_cents` keys |

A snapshot carries one currency. A company whose money is in more than one currency has `mrr_cents`,
`overdue_cents` and `currency` left out (with a logged warning) rather than summed or guessed; a company with no
subscriptions and no invoices has no currency to report in, so they are left out too. The snapshot is stamped
with `taken_at` = the start of the UTC day, so a retry or a second run the same day is the same snapshot upstream
(Dot.Analytics keeps the first one it received for a day).

Nothing is sent until `ANALYTICS_INGEST_URL` (the full URL ending `/api/ingest/snapshots`) **and**
`ANALYTICS_INGEST_SECRET` are set; the secret is the same value as `ANALYTICS_INGEST_SECRET_BILLING` in
Dot.Analytics' `.env`. Requests are HMAC-SHA256 signed over `"{timestamp}.{raw body}"` at send time, time out after
5 seconds, are retried up to 3 times with backoff, never throw into the app, and stop for 5 minutes (circuit
breaker) after 5 failures in a row, so an Analytics outage or a wrong URL cannot affect Billing. This needs the
scheduler (`* * * * * php artisan schedule:run`) and a queue worker (`php artisan queue:work`). Try it safely with
`php artisan billing:publish-metrics --dry-run` (prints the payloads, sends nothing) and `--company=<uuid>`.

**Safety.** Financial foreign keys are `RESTRICT` on PostgreSQL and MySQL, so a team's subscriptions, invoices,
payments and dunning history cannot be destroyed by deleting the team; the app refuses first with a message. This
means a team (and an account that owns one) with billing history cannot be deleted: retention over erasure,
pending an owner decision. SQLite (development and tests) keeps the old constraints and relies on the app-level
guard. Indexes exist for the statuses, due dates and `(team_id, status)` pairs the clock and scanner query.

**AI spend analysis** returns what the model actually said about this team's usage totals, labelled as AI-written.
With no `ANTHROPIC_API_KEY`, a failed call or an unusable answer it shows "AI analysis unavailable" and stores
nothing; there is no fallback copy. Viewers cannot trigger it.

### Configuration

| Variable | Meaning |
|---|---|
| `BILLING_INVOICE_NUMBER_FORMAT`, `BILLING_INVOICE_NUMBER_PAD` | invoice numbers, default `INV-{YYYY}-{seq}`, padded to 4 |
| `BILLING_PAYMENT_TERMS_DAYS` | days from issue to due date, default 14 |
| `BILLING_AUTO_FINALIZE_INVOICES` | `false` leaves generated invoices as drafts for a manager to finalize |
| `BILLING_TAX_RATE` | percentage applied to the subtotal, default `0` |
| `BILLING_ISSUER_NAME`, `_ADDRESS` (`\|`-separated lines), `_TAX_NUMBER`, `_EMAIL` | printed on the invoice; blank means not printed |
| `BILLING_FREE_SEAT_LIMIT`, `BILLING_FREE_STORAGE_GB` | entitlements for a company with no live subscription (placeholders) |
| `BILLING_ENTITLEMENT_SECRET_<KEY>` | per-platform secret for the entitlements API; blank = that platform cannot call |
| `NOTIFY_INGEST_URL`, `NOTIFY_INGEST_SECRET` | Dot.Notify ingest endpoint and this app's signing secret; blank disables reminders |
| `ANALYTICS_INGEST_URL`, `ANALYTICS_INGEST_SECRET`, `ANALYTICS_PLATFORM_KEY` | Dot.Analytics snapshot endpoint, shared signing secret, platform key (default `billing`); blank URL or secret disables publishing |

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13 |
| Language | PHP 8.3+ |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Database | PostgreSQL 16 (shared across ecosystem) |
| Realtime | Laravel Reverb (configured, not yet wired to any billing broadcast) |
| Auth | Laravel Sanctum (InfoDot SSO) + Jetstream/Fortify (teams, 2FA) |
| AI | Anthropic Claude (`claude-sonnet-4-6`), direct cURL via `AiBillingService` |
| Storage | Local (Flysystem); AWS S3 not currently configured |
| Search | Laravel Scout (dependency present; no search driver configured yet) |
| Queue | Database queue driver (`QUEUE_CONNECTION=database`); Redis/Horizon not installed |

## Quick Start

```bash
git clone https://github.com/sakhileb/Dot.Billing.git
cd Dot.Billing
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

> **Ecosystem SSO:** Set `DB_*` env vars to the shared InfoDot PostgreSQL instance and `APP_URL=https://billing.infodot.app`. Users authenticated through InfoDot gain access automatically via Sanctum handoff tokens.

### Running Tests

```bash
php artisan test
```

Feature tests use an in-memory SQLite connection (see `phpunit.xml`) and Laravel's default RefreshDatabase trait — no shared Postgres instance required to run them.

## Ecosystem

**Dot.Billing** is one of **21 platforms** in the InfoDot ecosystem, connected via shared PostgreSQL and Sanctum SSO. Visit [InfoDot](https://github.com/sakhileb/InfoDot) to explore the full platform map.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
