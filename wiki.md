---
title: Dot.Billing — Platform Wiki
version: 0.3.1
status: draft
owners: [Billing Platform Lead]
platform-id: dot-billing
last-review: 2026-08-01
---

# Dot.Billing

Purpose: this is Dot.Billing's own knowledge home — owned and maintained by the Dot.Billing team. It describes what this platform actually is, as implemented, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-billing.md)

---

## 1. What Dot.Billing Is

Dot.Billing is the subscription and billing management application for the InfoDot ecosystem: teams track their active plan, view and pay invoices, monitor per-platform usage, and (optionally) get AI-generated spend commentary. It is a Laravel 12 / Livewire 3 app, not a standalone payments processor — it models billing state (plans, subscriptions, invoices, payments, credits, alerts) and leans on Stripe identifiers as foreign references rather than owning payment processing itself.

**Status:** this is a working application skeleton — real models, migrations, and a dashboard exist and run — but the domain is thin. There is one dashboard route, three Livewire components, and no queue jobs, event listeners, or Stripe webhook handlers yet. Treat the "Roadmap" section (§8) as what's still ahead, and everything else as what's actually in the repo today.

## 2. Architecture

| Layer | Technology | Notes |
|---|---|---|
| Framework | Laravel 12, PHP 8.4 | Standard app skeleton (Jetstream + Fortify for auth/teams) |
| UI | Livewire 3, Alpine.js 3, Tailwind CSS | Server-rendered components, no SPA/API-first frontend yet |
| Database | PostgreSQL 16 | Shared instance across the InfoDot ecosystem (`DB_DATABASE=infodot`) |
| Auth | Laravel Sanctum + a custom `EcosystemAuthController` | SSO handoff from the InfoDot hub (`/auth/ecosystem`) |
| Realtime | Laravel Reverb | Configured (env vars present) but not wired to any billing broadcast yet |
| AI | Anthropic Claude, via `App\Services\AiBillingService` | Direct cURL call to the Messages API; falls back to canned copy if `ANTHROPIC_API_KEY` is unset |
| Payments | Stripe identifiers only (`stripe_subscription_id`, `stripe_invoice_id`, `stripe_payment_id`, `stripe_pm_id`) | No Stripe SDK, webhook endpoint, or charge-creation code exists in this repo yet — these are foreign-key style references for a Stripe integration that is not yet implemented here |

Team/user scoping runs through Jetstream's `Team` model (multi-tenant by team, not by individual user). Everything billing-related is scoped by `team_id`.

## 3. Domain Entities (as implemented)

Source: `database/migrations/2026_06_29_100001_create_billing_tables.php` and `app/Models/`.

| Model | Table | Purpose |
|---|---|---|
| `BillingPlan` | `billing_plans` | Subscription tier — pricing (monthly/annual), seat limit, storage cap, feature list (JSON) |
| `BillingSubscription` | `billing_subscriptions` | Team-to-plan mapping — status (`active`/`trialing`/`past_due`/`canceled`/`expired`), billing cycle, period dates, trial |
| `BillingInvoice` | `billing_invoices` | Generated invoice — subtotal/tax/total, status (`draft`/`open`/`paid`/`void`/`uncollectible`), due/paid dates |
| `BillingInvoiceItem` | `billing_invoice_items` | Line item on an invoice |
| `BillingPayment` | `billing_payments` | Payment attempt against an invoice — status (`succeeded`/`failed`/`refunded`/`pending`), method, failure reason |
| `BillingPaymentMethod` | `billing_payment_methods` | Stored card/EFT reference (last4, brand, expiry) |
| `BillingUsageRecord` | `billing_usage_records` | Per-platform metric consumption (`platform`, `metric`, `quantity`, `recorded_at`) — the hook other ecosystem platforms would report usage through |
| `BillingCredit` | `billing_credits` | Account credit/adjustment, with optional expiry |
| `BillingAlert` | `billing_alerts` | Budget/threshold alert — type, threshold metric/value, status (`active`/`triggered`/`dismissed`) |

Only `BillingSubscription` and `BillingPlan` currently carry business-logic helper methods (`isActive()`, `isTrialing()`, `isFree()`). The rest are plain Eloquent models — validation, state transitions, and lifecycle rules for invoices/payments/alerts are not yet implemented.

## 4. What Exists Today vs. What's Modeled but Unbuilt

To keep this honest for anyone integrating against Dot.Billing:

**Built:**
- Ecosystem SSO route (`/auth/ecosystem`) and Sanctum-gated dashboard
- Dashboard view summarizing plan name, open invoice count, YTD paid total, active alert count (`routes/web.php`)
- Three Livewire components: `BillingOverview` (current subscription + next invoice), `InvoiceTable`, `UsageDashboard`
- `AiBillingService::analyzeSpend()` — calls Claude directly over cURL for spend insights, with a safe fallback when no API key is configured

**Modeled in the schema but not yet built:**
- Stripe integration (no SDK dependency, no webhook route, no charge/subscription creation code)
- Any queued jobs, event/listener classes, or scheduled commands (none exist in `app/` as of this writing)
- Invoice generation logic (the `billing_invoices` table exists; nothing populates it programmatically yet beyond seeders/factories if present)
- Usage ingestion API for other platforms to report `billing_usage_records` (the table and index exist; no endpoint consumes it)
- Public API beyond the single Sanctum `/api/user` route

## 5. Events Emitted

**None yet.** There are no Laravel event/listener classes, no domain events, and no outbound webhook or message-bus code in this repository. The table below states the intent — these are the events Dot.Brain's ingested view (§7) expects Dot.Billing to eventually emit, not events this codebase currently fires:

| Planned event | Would trigger on | Status |
|---|---|---|
| `finance.settlement.completed` / `finance.settlement.failed` | Payment processed against an invoice | not implemented |
| `finance.payout.scheduled` / `finance.payout.released` | Payout cycle (payouts to merchants/producers are not modeled in this schema at all yet) | not implemented |
| `finance.subscription.renewed` / `finance.subscription.lapsed` | `BillingSubscription` status transition | not implemented |
| `finance.dunning.opened` / `finance.dunning.closed` | Recovery case on `BillingAlert`/`BillingPayment` failure | not implemented |

Until these exist, any Knowledge Pack Dot.Billing publishes to Dot.Brain would have to be generated by a manual/batch process reading these tables directly, not by real-time event capture.

## 6. Security & Compliance Posture

Stated plainly, because this platform touches payment data: **this repository does not currently implement PCI-DSS controls, and no compliance claim should be inferred from it.** Card data fields in `billing_payment_methods` are limited to non-sensitive display fields (`last4`, `brand`, `exp_month`, `exp_year`) plus a `stripe_pm_id` reference — which is consistent with tokenized-card handling via Stripe, but there is no Stripe integration code in this repo to verify that pattern is actually followed end-to-end. Compliance posture (PCI scope, data residency, encryption at rest/in transit, audit logging) is a roadmap item (§8), not a shipped guarantee.

## 7. Connecting to Dot.Brain

Dot.Billing is registered in Dot.Brain's platform map as `dot-billing` (payments and subscriptions). Dot.Brain's ingested view — including the intended settlement-domain framing, aggregation-floor configuration for money-movement data, and a worked publish→PR round-trip example — is maintained at [`platforms/dot-billing.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-billing.md). That document describes the target integration; this wiki describes what actually ships in this repo today. The gap between the two is intentional and tracked in §4 and §8 — Dot.Brain's ingested view is somewhat ahead of the current codebase, describing intended settlement/payout/dunning domains this repo doesn't yet model.

Once event emission (§5) exists, Dot.Billing would publish Knowledge Packs to Dot.Brain following the same shape as other platforms:

| Payload type | Would contain |
|---|---|
| `observation` | Aggregated invoice/payment/usage metrics — never individual transaction detail |
| `insight` | Patterns from `AiBillingService` spend analysis, generalized |
| `outcome` | Verification of any Dot.Brain recommendation (e.g., plan-upgrade nudges) |
| `incident` | Payment failures, billing outages |

Given the sensitivity of money-movement data, any aggregation published outward should default to a stricter-than-ecosystem-default anonymity floor (Dot.Brain's ingested view proposes n≥50 for settlement/payout, n≥100 for dunning) — this repo has no aggregation or publishing code yet, so these floors are not enforced anywhere today; they are a requirement to design in before publishing begins.

## 8. Roadmap / Open Questions

- [ ] Stripe SDK integration: subscription creation, invoice generation, webhook handling
- [ ] Domain events for subscription lifecycle, payment outcomes, and alerts (prerequisite for any Knowledge Pack publishing)
- [ ] Usage ingestion endpoint so other Dot platforms can report `billing_usage_records`
- [ ] Payout modeling — the schema currently has no payout/merchant-disbursement tables at all, despite Dot.Brain's ingested view describing a payout domain
- [ ] Define and implement the actual PCI/compliance posture rather than inferring one from field names
- [ ] Decide whether `AiBillingService`'s direct-cURL Claude integration should move to a shared ecosystem AI client, if/when one exists
- [ ] Aggregation-floor enforcement before any outward-facing Knowledge Pack publishing begins
## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.3.1 | 2026-08-01 | Billing Platform Lead | Swapped the placeholder monogram for the real ecosystem-issued Dot.Billing logo (`Dot.logos/dot.billing.png`) across favicon, nav mark, and login page; removed the stale `public/dot_projects.png` leftover from the shared template |
| 0.3.0 | 2026-08-01 | Billing Platform Lead | UI/branding pass: invoice detail page + search, Livewire loading/empty states, database-channel notification bell, class-based dark mode toggle, placeholder logo/favicon, `BillingInvoicePolicy` security fix, and Feature tests — see README and inline `TODO(branding)` comments for details |
| 0.2.0 | 2026-08-01 | Billing Platform Lead | Initial platform-owned wiki, derived from the actual Laravel codebase (models, migrations, routes, services) plus Dot.Brain's ingested view for ecosystem framing; explicitly flags the gap between Dot.Brain's target-state description and current implementation |
