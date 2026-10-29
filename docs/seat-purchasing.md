# Extra seat purchasing (R-13)

A company can buy seats beyond the ones its plan includes. The service layer is below; the customer screen is
`/seats` (see "The Seats screen"). Payment is the existing Paystack path; nothing here adds a payment route.

## The one rule that matters

**Nothing is charged unless a price is configured.** Each plan has two nullable columns,
`extra_seat_price_monthly_cents` and `extra_seat_price_annual_cents`. They have no default, anywhere. `null`
(or 0) means that plan sells no extra seats for that cycle, and any attempt to add seats is refused with no
invoice. No amount is hard coded in the application; all arithmetic goes through `App\Support\Money`.

`BillingPlan::extraSeatPriceFor($cycle): ?Money` returns the price or `null`; `sellsExtraSeats($cycle)` is true
only for a positive price.

## Data

| Where | Column | Meaning |
|---|---|---|
| `billing_plans` | `extra_seat_price_monthly_cents`, `extra_seat_price_annual_cents` | unsigned int, nullable, no default |
| `billing_subscriptions` | `extra_seats` | unsigned smallint, default 0: seats that are **paid for** and count toward the entitlement |
| `billing_subscriptions` | `pending_extra_seats` | nullable: a reduction scheduled for the next renewal |
| `billing_seat_changes` | new table | one row per request: key, kind (`add`/`reduce`/`keep`), from/to, status, linked invoice |

The migration (`2026_10_29_100000_add_extra_seat_columns.php`) is additive and guarded; running it twice is a no-op.
`billing_seat_changes` is not in the original brief. It is needed so a request key is idempotent and so a seat
purchase is tied to its invoice, which is how "counts only after that invoice is settled" is enforced.

## `App\Services\Billing\SeatChange`

```php
app(SeatChange::class)->change($subscription, targetExtraSeats: 5, requestKey: 'any-unique-string', actor: $user);
```

The caller gives the **target** number of extra seats, not a delta. Authorization is the caller's job (as in
`SubscriptionService`). Everything runs in a DB transaction with the subscription row locked.

**Refused** (`BillingRuleViolation`, with a reason code): `extra_seats_not_sold` (no price on the plan for the
subscription's cycle), `invalid_state` (subscription not live), `invalid_seat_count` (negative),
`seat_limit_exceeded` (above `billing.seats.max_extra`, default 500), `invalid_request_key`,
`idempotency_conflict` (key reused for a different count), `seat_change_pending` (an earlier purchase is still
unpaid), `trial_no_extra_seats` (trials are not invoiced), `period_ended` (no full day left), `nothing_to_charge`.

**Adding seats.** An invoice is raised for the rest of the current period, daily pro rata:

    amount = round_half_up( unit_price x seats added x days_left / days_in_period )

Days are whole calendar days of the period's actual length (a 28 day February period divides by 28; a 365 day
annual period by 365); today counts as a day left. Tax uses the same `billing.invoice.tax_rate_percent` as every
invoice. The line reads `Extra seats x n, pro rata d of D days`, with the full-period unit price as its snapshot.
The invoice is not tied to a billing period (`period_start`/`period_end` are null, as on legacy invoices),
so it cannot collide with the period invoice.

The added seats **do not count** while that invoice is unpaid or only partly paid. `subscription.extra_seats`
changes only when `RecordPayment` marks the invoice paid in full (`SeatChange::settleInvoice`), the same path
for a manual payment and for a Paystack webhook or redirect, including replays (a replay cannot apply twice).

**Reducing seats.** Scheduled: `pending_extra_seats` is set, `extra_seats` and the entitlement stay as they are,
nothing is refunded or credited and no invoice is raised. `SubscriptionLifecycle` applies it when the period
rolls (`SeatChange::onRenewal`) and the renewal invoice bills the lower count. The latest request before renewal
wins; asking for the current count cancels a scheduled reduction. Reducing is allowed even if the plan has since
lost its price.

**Idempotency.** Per (subscription, request key). The same key and count returns the first result
(`->replayed === true`) and changes nothing; a concurrent duplicate resolves to the same row.

**Audit.** Entries in the existing `AuditLog`: `seats.add_invoiced`, `seats.applied`, `seats.reduction_scheduled`,
`seats.reduction_cancelled`, `seats.reduction_applied`, `seats.lapsed`, `seats.dropped_no_price`,
`seats.settled_not_applied`, plus the normal `invoice.generated` / `payment.recorded` / `invoice.paid`.

## Invoices and entitlement

- Period invoices (`InvoiceGenerator`): when `extra_seats > 0` and the plan has a price for the cycle, a second
  line `Extra seats x n` (quantity n, unit price snapshot) is added and included in subtotal, tax and total.
  With 0 extra seats the invoice is exactly what it was before (a test proves it). A free plan holding paid
  extra seats is invoiced for the seats line only.
- `EntitlementResolver`: `seat_limit` = plan seat limit + settled `extra_seats` (`null` stays `null`, unlimited).
  New key `extra_seats` (0 when there is no live subscription). Every other key is unchanged. Consumers that
  compare the whole response will see the new key.

## Lifecycle edge cases (decided here, easy to change)

- An unpaid purchase still open when the period ends lapses and its invoice is voided; a late payment cannot
  then apply stale seats. Voiding the invoice by hand also cancels the purchase (frees the "one unpaid at a time" rule).
- At renewal, seats are dropped to 0 if the plan the subscription renews onto has no price for the cycle, so
  seats are never carried for free (also covers a queued plan change).
- A partly paid purchase is left alone at renewal (it is not voided because money was taken). If it is later paid
  in full its seats apply, but the renewal invoice already raised will not have included them.
- An unpaid pro-rata invoice is an open invoice like any other, so after its due date it can make the
  subscription `past_due` through the existing rule.

## Defaults I chose, which the owner must confirm

All of these are configuration or one small method, not structure:

| Decision | Default | Where |
|---|---|---|
| Adding seats charges the rest of the current period, daily pro rata, rounded half up | `prorata` | `billing.seats.add_charge` (`BILLING_SEATS_ADD_CHARGE`): `prorata` or `full_period` |
| Reducing is deferred to the period end, no refund, no credit | fixed | `SeatChange::reduce` / `onRenewal` |
| Most extra seats per subscription | 500 | `billing.seats.max_extra` (`BILLING_SEATS_MAX_EXTRA`) |
| One unpaid purchase at a time | on | `SeatChange::perform` |
| No seat purchase during a trial | on | `SeatChange::perform` |
| Past-due subscriptions may still buy seats | allowed | not restricted |

## The Seats screen (`/seats`)

A customer screen for a company's **owner or admin** (the existing `manage-billing` gate against the current team; a plain
member, a viewer, a person whose current team is not theirs, and a guest are refused, and so is every Livewire action). The
sidebar shows a "Seats" link to the same people. It shows:

- the live plan and billing cycle, the included seats (the plan's `seat_limit`, or "Unlimited"), the settled extra seats and
  the total, the period end, and the extra-seat price for the team's cycle. No price (or 0) says plainly that extra seats are
  not available on the plan and offers no form; nothing is defaulted. A priceless plan that still holds seats can only reduce.
- an unpaid pro-rata purchase with its invoice, a **Pay now** form posting to the existing `invoices.pay` route (Paystack
  hosted checkout, `InvoicePaymentController`) and a link to the invoice; with Paystack unconfigured it says why instead.
- a reduction scheduled for period end ("drops from X to Y on ..., nothing is refunded").
- a form for the desired **total** of extra seats. It calls `SeatChange::change` with a request key minted for the form and
  kept until the number changes, so a double submit or a re-sent request is a replay (one invoice, one row). The result says
  what was invoiced now (pro rata), that the seats count only after payment, or that a reduction takes effect at period end
  with no refund. `SeatChange`'s refusals are shown in its own plain words.

Component: `App\Livewire\Billing\SeatsPanel`; tests: `tests/Feature/Billing/SeatsScreenTest.php`.

## What remains

- An operator screen to change a team's seats on its behalf (the customer screen exists).
- Owner confirmation of the defaults above, and of whether seats should be restricted for `past_due` teams.
- Consuming apps (InfoDot and others) reading `seat_limit` need no change; they may show `extra_seats`.
- Shipping the migration to production is a normal deploy step; nothing here was deployed.
