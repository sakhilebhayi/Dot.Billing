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
