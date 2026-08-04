---
title: Dot.Billing — Platform Wiki
version: 0.7.1
status: draft
owners: [Billing Platform Lead]
platform-id: dot-billing
last-review: 2026-08-04
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
| AI | Anthropic Claude, via `App\Services\AiBillingService` | Direct cURL call to the Messages API, with a 5s connect / 15s total timeout; falls back to canned copy if `ANTHROPIC_API_KEY` is unset or the live call fails |
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

**Dot.Billing is the ecosystem's first-mover on the Dot Knowledge Protocol** (2026-08-02) — step 1 of the six-step onboarding procedure in Dot.Brain's [`os/05-Knowledge-Protocol.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/os/05-Knowledge-Protocol.md) §5 is done for real, not just documented:

- [`platform.dkp.json`](platform.dkp.json) — a real, schema-valid manifest declaring `dot-billing`'s signing key, `pr_repository`, and topics
- A real Ed25519 keypair — public half committed in the manifest above; private half at `storage/app/private/dkp-signing.key`, gitignored, never committed (see that directory's `README.md` for the rotation procedure)
- [`app/Console/Commands/PublishDkpMetricPack.php`](app/Console/Commands/PublishDkpMetricPack.php) — the one hand-run publish script, following the "one script, not a pipeline" discipline in `os/05-Knowledge-Protocol.md` §5. It computes `billing.invoice_payment_success_rate` from real `billing_invoices` columns (`status`, `due_date`, `paid_at`), publishes actual `observations` when the database has invoice rows and honestly omits them when it doesn't, canonicalizes the pack (sorted keys, RFC 8785-shaped), and signs it with `sodium_crypto_sign_detached`
- `storage/app/dkp/packs/` — one real signed pack committed as evidence, produced against this environment's actual (empty) database, so `body.observations` is honestly absent rather than fabricated; `confidence: 0.30` reflects that this is a verified *definition*, not yet a verified *measurement*

It still does not transmit anywhere — DKP's transport layer (mTLS, tenant topics) is unbuilt ecosystem-wide, per `os/05-Knowledge-Protocol.md` §6 — and registration (onboarding step 2) hasn't happened yet either. What exists is real: a real keypair, a real manifest that validates against Dot.Brain's `schemas/platform-manifest.schema.json`, and one real pack that validates against `schemas/knowledge-pack.schema.json` and verifies against the committed public key.

Once event emission (§5) and Stripe integration exist, Dot.Billing would extend this to the other payload types:

| Payload type | Would contain |
|---|---|
| `metric` | Aggregated invoice/payment/usage metrics — never individual transaction detail (the one payload type actually wired up today) |
| `insight` | Patterns from `AiBillingService` spend analysis, generalized |
| `outcome` | Verification of any Dot.Brain recommendation (e.g., plan-upgrade nudges) |
| `incident_report` | Payment failures, billing outages |

Given the sensitivity of money-movement data, any aggregation published outward should default to a stricter-than-ecosystem-default anonymity floor (Dot.Brain's ingested view proposes n≥50 for settlement/payout, n≥100 for dunning) — the publish command above has no aggregation-floor enforcement yet, so this is a requirement to add before any pack with real per-team observations is published.

## 8. Roadmap / Open Questions

- [ ] Stripe SDK integration: subscription creation, invoice generation, webhook handling
- [ ] Domain events for subscription lifecycle, payment outcomes, and alerts (prerequisite for the other 3 DKP payload types)
- [ ] Usage ingestion endpoint so other Dot platforms can report `billing_usage_records`
- [ ] Payout modeling — the schema currently has no payout/merchant-disbursement tables at all, despite Dot.Brain's ingested view describing a payout domain
- [ ] Define and implement the actual PCI/compliance posture rather than inferring one from field names
- [ ] Decide whether `AiBillingService`'s direct-cURL Claude integration should move to a shared ecosystem AI client, if/when one exists
- [ ] Aggregation-floor enforcement before any outward-facing pack carries real per-team observations (§7)
- [ ] DKP onboarding step 2 (registration) and beyond — step 1 is done (§7); the transport layer it would publish over doesn't exist anywhere in the ecosystem yet

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.7.1 | 2026-08-04 | Platform-loop pass | **Null-`currentTeam` crash fix** (ecosystem-wide sweep, mirroring Dot.Mines commit `0cc4362`). This platform has no `EnsureTeamContext`-style middleware and no route/middleware anywhere forces a team to exist before an authenticated page renders — a user removed from their last team (or never assigned one, e.g. `User::factory()->create(['current_team_id' => null])`) reaches any authenticated route with `Auth::user()->currentTeam` genuinely null. `HasTeamScope` (added in 0.7.0) already fails closed on *queries* for this case, but it doesn't protect a bare `currentTeam->someMethod()` dereference. Found two such unguarded call sites via `grep -rn "currentTeam\|current_team_id" app/`: (1) `app/Livewire/Billing/BillingOverview.php:15` (`subscription()` computed property) — `auth()->user()->currentTeam->subscription()->with('plan')->first()`, called unconditionally by the Blade view (`@if($this->subscription)`) on every render, so `Call to a member function subscription() on null` on any teamless dashboard visit; (2) `app/Livewire/Billing/UsageDashboard.php:30` (`analyzeSpend()`, a `wire:click` action) — `$service->analyzeSpend(auth()->user()->currentTeam, ...)` against `AiBillingService::analyzeSpend(Team $team, ...)`'s non-nullable `Team` type hint, so a teamless user clicking "AI Spend Analysis" got a `TypeError`, not a friendly error. Both are nested Livewire components rendered inside the `/dashboard` route closure (`routes/web.php`), not routes themselves, so the fix lives in the components: added a private `resolveCurrentTeam(): ?Team` helper to each (returns `Auth::user()?->currentTeam`), added `mount()` to each that redirects to `route('teams.create')` (confirmed live via `php artisan route:list`, Jetstream's stock team-creation screen — this app has no separate onboarding route) when `resolveCurrentTeam()` is null, changed `subscription()` to return `null` instead of dereferencing when teamless (the view's existing `@if` already handles that), and changed `analyzeSpend()` to `abort(403, 'No active team selected.')` when teamless, matching the message convention already used by `InvoiceController`/`BillingInvoicePolicy`'s documented reasoning in this repo. Verified via Livewire's `SupportRedirects` internals (`vendor/livewire/livewire/src/Features/SupportRedirects/SupportRedirects.php`) that a nested component's `mount()`-time redirect does `abort(redirect($to))` for non-Livewire (i.e. initial full-page) requests, so the redirect applies to the whole `/dashboard` response even though these are child components, not the route handler. No third occurrence found: `InvoiceTable.php` only queries through `HasTeamScope`-scoped models (no direct `currentTeam` access), and the `/dashboard` route closure itself only queries scoped models too (already safe, confirmed by reading it). Spot-checked the `HasTeamScope` trait rollout from 0.7.0 — still intact and correctly applied to all five `team_id`-bearing models (`BillingSubscription`, `BillingInvoice`, `BillingPayment`, `BillingUsageRecord`, `BillingAlert`); no gap found, nothing changed there. Added `test_authenticated_user_with_no_team_is_redirected_to_team_creation` to `tests/Feature/Billing/BillingTest.php`, mirroring Dot.Mines' regression test: creates a user with `current_team_id => null`, hits `/dashboard`, asserts `assertRedirect(route('teams.create'))`. Full suite against a fresh isolated `dot_billing_audit_test` Postgres database (role `postgres`/`postgres`, dropped after the run): 67 tests, 60 passed / 7 skipped (pre-existing Jetstream-scaffold skips, unrelated) / 0 failed, 116 assertions — one more test and one more pass than 0.7.0's baseline (66/59), accounting for the new regression test. Left alone, out of scope for this pass: DB schema/migrations/RLS, queue/cache/search-index configuration (no queued jobs exist in this repo yet per §4, so nothing to check there for auth-context assumptions). |
| 0.7.0 | 2026-08-04 | Platform-loop pass | **Global tenant scope for every team-owned billing model** (matching Dot.Finance's `HasUserScope`/Dot.Notify's `HasTeamScope` pattern from the ecosystem-wide architecture-hardening pass). Added `app/Models/Concerns/HasTeamScope.php`: a trait using `addGlobalScope` in `bootHasTeamScope()` to scope every query on the using model to `Auth::user()->currentTeam->id`, or fail closed (`whereRaw('1 = 0')`) if the user is authenticated but has no current team. Applied to `BillingSubscription`, `BillingInvoice`, `BillingPayment`, `BillingUsageRecord`, and `BillingAlert` — the five models with a `team_id` column per `database/migrations/2026_06_29_100001_create_billing_tables.php`. Deliberately **not** applied to `BillingPlan` (shared/global pricing catalog, no `team_id` column) or `BillingInvoiceItem` (no `team_id` of its own; only ever reached through an already-scoped `BillingInvoice::items()` relation). `BillingPaymentMethod`/`BillingCredit` have no Eloquent model yet (migrated but unbuilt, per §4), so nothing to scope there. Removed now-redundant explicit `where('team_id', ...)` read-side filters from `app/Livewire/Billing/BillingOverview.php`, `InvoiceTable.php`, `UsageDashboard.php`, and the `/dashboard` route closure in `routes/web.php` — the model itself now refuses to return another team's rows regardless of whether a controller remembers to filter. Mass-assignment (`team_id` still set explicitly at `create()` time) and `InvoiceController@show`'s `Gate::authorize('view', $invoice)` policy check were left untouched, as intended — the scope is defense in depth, not a replacement for the policy. No Stripe webhook/unauthenticated-context billing endpoints exist in this repo yet, so there was nothing to guard there. **Behavior change:** `test_user_cannot_view_another_teams_invoice` now asserts `assertNotFound()` instead of `assertForbidden()` — because implicit route-model binding queries through the new scope, another team's invoice 404s before `BillingInvoicePolicy` ever runs; this is a fail-closed improvement (no dependency on the controller remembering to authorize), not a regression. Added `test_scope_alone_blocks_cross_team_access_even_without_a_policy_check` to `tests/Feature/Billing/InvoiceViewTest.php`, proving the scope alone (no Gate call anywhere in the path) blocks `BillingInvoice::find()`/`::query()->count()` for a non-member and restores full access for the owning team, matching the regression-test pattern from Dot.Finance/Dot.Notify. Added `phpstan.neon.dist` (level 5, `includes: [vendor/larastan/larastan/extension.neon]`, `paths: [app]`) plus `larastan/larastan` and `phpstan/phpstan` as dev dependencies; `vendor/bin/phpstan analyse --memory-limit=1G` — config present, execution unverified in this sandbox (no stdout/stderr, no useful exit status). Bumped `guzzlehttp/guzzle` 7.12.3→7.15.2, `guzzlehttp/psr7` 2.12.3→2.13.0, `guzzlehttp/promises` 2.5.0→2.5.1 via `composer.lock` (`composer audit` was already clean going in; the bump had been pre-applied and re-verified clean: "No security vulnerability advisories found."). Full suite against a fresh isolated `dot_billing_pilot` Postgres database: 66 tests, 59 passed / 7 skipped (pre-existing Jetstream-scaffold skips, unrelated to this change) / 0 failed, 114 assertions. |
| 0.6.1 | 2026-08-03 | Sakhile Bhayi | Fixed a lingering branding gap: `application-logo.blade.php` (and, where present, `application-mark.blade.php`) still rendered Jetstream's stock placeholder SVG wordmark in the app sidebar/nav and other authenticated-app surfaces, even though the login page's own `authentication-card-logo.blade.php` and the marketing welcome page already used the real logo. These two components render on every authenticated page via Jetstream's own layout, so the placeholder was visible constantly, not just on one screen. Swapped to the real logo file, matching the asset path already used elsewhere in this repo. |
| 0.6.0 | 2026-08-03 | Sakhile Bhayi | `resources/views/welcome.blade.php` was still the unmodified default Laravel/Jetstream scaffold — no marketing content, no logo, no hero section existed to swap. Built a full custom marketing page from scratch, matching the structural pattern piloted on `mines`/Dot.Mines (fixed nav + hero + features + platform + CTA + footer), with the real `public/images/logo.png` lockup in the nav and footer, and a real photographic hero background: a desk-with-calculator/invoices/binders photo by Cht Gsml (@karepesinde), unsplash.com/photos/desk-with-calculator-binders-notebook-and-glasses--6LEDthF1AI, hotlinked via Unsplash's CDN (`images.unsplash.com/photo-1762427355235-dd22e5cb010c`) under a dark gradient overlay. Copy is drawn honestly from this wiki's own §3/§4 domain-entity and "built vs. modeled but unbuilt" sections — describes only what's actually shipped (subscription overview, invoice table, usage dashboard, `AiBillingService` spend commentary with fallback, ecosystem SSO) and does not claim Stripe processing, webhooks, or invoice-generation logic that don't exist yet. No fabricated stats or testimonials. Verified the image URL resolves with `curl -sI` (HTTP/2 200) before committing. |
| 0.5.0 | 2026-08-02 | Sakhile Bhayi | **Shared-`infodot`-database migration collision fixed** (Dot.Brain ADR-0013): every platform ships its own copy of the six Jetstream-core migrations (users/teams/team_user/team_invitations/personal_access_tokens/two-factor columns), and running two platforms' migrations against the same real `infodot` database was found to collide — Dot.Forms' migration failed on a column Dot.Billing's had already added. Guarded this platform's six copies with `Schema::hasTable`/`hasColumn` checks so they're safe regardless of run order. Verified for real: reset `infodot`, ran Dot.Billing → Dot.Forms → Dot.Tutor's full migration sets back-to-back against the same database, zero errors, no duplicate columns; each platform's test suite still passes unchanged. |
| 0.4.0 | 2026-08-02 | Billing Platform Lead | **DKP onboarding step 1, done for real** (§7): generated a real Ed25519 keypair (public half committed at `platform.dkp.json`, private half gitignored at `storage/app/private/dkp-signing.key`); wrote the manifest, validated by hand against Dot.Brain's `schemas/platform-manifest.schema.json`; wrote `app/Console/Commands/PublishDkpMetricPack.php`, the one hand-run publish script for `billing.invoice_payment_success_rate`; produced and committed one real signed pack at `storage/app/dkp/packs/` — signature verified against the committed public key, `observations` honestly empty since this environment's database has no invoice rows. This is Dot.Billing's (and the ecosystem's) first real, verifiable DKP artifact — see Dot.Brain `os/05-Knowledge-Protocol.md` §5 and `os/19-Knowledge-Packs.md` §5 for why this was scoped this narrowly. |
| 0.3.2 | 2026-08-01 | Billing Platform Lead | Incremental pass: fixed a config-key mismatch in `AiBillingService` — it read `services.anthropic.key`, but `config/services.php` only ever defined `services.anthropic.api_key`, so the API key was always empty and the "live AI insights" path was silently dead code regardless of `ANTHROPIC_API_KEY` being set. Also added explicit cURL connect/total timeouts (5s/15s) so a slow or unreachable Anthropic API can't hang the request, and made a failed live call fall back to the same honest canned copy used for the no-key case instead of an empty insights array. Added `tests/Feature/Billing/AiBillingServiceTest.php` (written but unexecuted — see [Dot.Brain 02-Engineering-Loop.md](../Dot.Brain/os/02-Engineering-Loop.md) §2 on this environment's constraints). |
| 0.3.1 | 2026-08-01 | Billing Platform Lead | Swapped the placeholder monogram for the real ecosystem-issued Dot.Billing logo (`Dot.logos/dot.billing.png`) across favicon, nav mark, and login page; removed the stale `public/dot_projects.png` leftover from the shared template |
| 0.3.0 | 2026-08-01 | Billing Platform Lead | UI/branding pass: invoice detail page + search, Livewire loading/empty states, database-channel notification bell, class-based dark mode toggle, placeholder logo/favicon, `BillingInvoicePolicy` security fix, and Feature tests — see README and inline `TODO(branding)` comments for details |
| 0.2.0 | 2026-08-01 | Billing Platform Lead | Initial platform-owned wiki, derived from the actual Laravel codebase (models, migrations, routes, services) plus Dot.Brain's ingested view for ecosystem framing; explicitly flags the gap between Dot.Brain's target-state description and current implementation |
