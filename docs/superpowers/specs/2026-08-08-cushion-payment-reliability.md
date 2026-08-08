# Dot.Billing: Cushion — Payment Reliability — Design

## Context

This is the second pilot implementation of the shared cross-ecosystem
"cushion" contract defined in Dot.Brain's
[brain.cushion.md](https://github.com/sakhilebhayi/Dot.Brain/blob/main/brain.cushion.md),
proving the contract generalizes beyond Dot.Finance's reserve-runway
dimension. Dot.Billing has no cash-reserve data at all (it's a
subscription-billing app, not a general ledger) — but it has real
`BillingInvoice`/`BillingPayment` data supporting a genuinely different
resilience dimension: `payment_reliability`.

Existing real fields: `BillingInvoice.status`
(`draft`/`open`/`paid`/`void`/`uncollectible`), `due_date`, `paid_at`.
`BillingPayment.status` (`succeeded`/`failed`/`refunded`/`pending`).

## Goal

Show each team, on their existing billing dashboard, their real on-time
payment rate over a trailing window — honestly, including when there's
nothing to compute from yet.

## Computation (`App\Services\PaymentReliabilityCalculator`)

- **Window**: trailing 6 months, by `due_date`.
- **Eligible invoices**: those where a due-date has already passed and the
  invoice was genuinely issued — `status = 'paid'`, OR
  (`status = 'open'` AND `due_date < now()`), OR `status = 'uncollectible'`.
  Excludes `draft` (never issued) and `void` (cancelled — never came due
  in a meaningful sense) and not-yet-due `open` invoices (can't judge
  on-time-ness before the due date arrives).
- **On-time count**: eligible invoices where `status = 'paid'` AND
  `paid_at <= due_date`.
- **On-time rate**: `on_time_count / eligible_count * 100`, rounded to the
  nearest whole percent (invoice counts are small integers — false
  decimal precision would misstate the sample size).
- **Insufficient data**: zero eligible invoices in the window → the whole
  section is omitted, not shown as 0% or "N/A".
- **What-if** (only when real data supports it): count currently-overdue
  invoices (`status = 'open'` AND `due_date < now()`, i.e. still unpaid
  past their due date). If any exist, compute what the on-time rate would
  be if those specific invoices were paid on time in the same window
  (added to both the eligible and on-time counts, since paying them now
  wouldn't make them retroactively "on time" for the original due date —
  see note below). If there are no overdue invoices, the what-if is
  omitted.

**Honesty note on the what-if:** an invoice paid *after* its due date is
never actually "on time," even if paid today. The what-if therefore
doesn't claim "if these were paid on time" — it's framed as "if your N
currently-overdue invoices were resolved" and shows the resulting
proportion, worded to avoid implying retroactive on-time-ness.

## Return Contract

```php
[
    'available' => bool,
    'reason' => ?string,               // 'insufficient_data' when available=false
    'eligible_invoice_count' => ?int,
    'on_time_count' => ?int,
    'on_time_rate_pct' => ?int,
    'basis' => ?string,
    'what_if' => ?array,               // ['overdue_count' => int, 'projected_resolved_rate_pct' => int] or null
]
```

## UI

A new Livewire component, `App\Livewire\Billing\PaymentReliability`,
mirroring the existing `BillingOverview` component's structure (team-scoped
via `HasTeamScope`, `#[Computed]` property, `wire:loading` skeleton),
rendered via `<livewire:billing.payment-reliability />` on
`resources/views/dashboard.blade.php`, placed alongside the existing
`<livewire:billing.billing-overview />` include. Card styling matches the
existing `.dot-card` convention used throughout this dashboard.

## Testing Plan

- `PaymentReliabilityCalculatorTest`: eligible-invoice filtering excludes
  draft/void/not-yet-due-open, on-time rate computed correctly against a
  fixture mix of on-time/late/uncollectible invoices, insufficient-data
  case (zero eligible) returns `available: false`, what-if computed
  correctly against a fixture with real overdue invoices, what-if is
  `null` when there are no overdue invoices, team-scoping is respected
  (a second team's invoices never affect the first team's rate — verified
  directly since `HasTeamScope` applies automatically to the underlying
  queries).
- `PaymentReliabilityComponentTest` (Livewire component test): renders
  without error for a team with eligible invoices, renders without the
  cushion content for a team with none.

## Explicitly Out of Scope

- Any other cushion dimension (Dot.Billing's data model doesn't support
  reserve/concentration/capacity dimensions).
- DKP publication of this metric (per `brain.cushion.md`'s explicit scope
  boundary).
- `BillingPayment.status` (succeeded/failed/refunded) is not used in this
  version's computation — invoice-level on-time-ness (via `status`/
  `due_date`/`paid_at`) is a cleaner, more directly interpretable signal
  than payment-attempt success rate, and avoids double-counting when an
  invoice has multiple payment attempts. A payment-attempt-based dimension
  could be a separate, later addition, not conflated with this one.
