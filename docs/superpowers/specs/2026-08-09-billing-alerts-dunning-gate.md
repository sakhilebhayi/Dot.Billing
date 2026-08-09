# Billing Alert Scanning + Dunning Approval Gate — Design Spec

## Context

This spec is part of the ecosystem-wide Autonomy & Owner-Independence Program
(per [brain.autonomy.md](https://github.com/sakhilebhayi/Dot.Brain/blob/main/brain.autonomy.md)
§2), applied here to Dot.Billing.

**The platform audit was checked against real code and found accurate.**
[`Dot.Brain/platforms/dot-billing.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-billing.md)
reports zero background automation — confirmed directly: `routes/console.php`
has only the stock `inspire` command, `app/Jobs/` doesn't exist, and both
`App\Notifications\InvoiceDueNotification` and
`App\Notifications\PaymentFailedNotification` are written and tested but
explicitly documented in their own docblocks as "not yet wired to any
automatic trigger."

**This is not an invented gap.** The audit's own Gap Summary names the exact
next step: *"The two Notification classes... are the nearest scaffolding —
wiring them to real invoice/payment lifecycle events would be routine
reporting/monitoring (Level 1..."* — and the platform's [`wiki.md`](wiki.md)
§5 separately names `finance.dunning.opened`/`finance.dunning.closed` as an
intended-but-unbuilt event pair, triggered by "recovery case on
`BillingAlert`/`BillingPayment` failure."

Unlike Dot.Auction, this platform is genuinely a **working skeleton** — no
Stripe integration, nothing populates `billing_invoices` beyond seeders. But
enough real schema already exists to build the Level 1 piece the audit
names, plus the one genuine judgment call in this domain, without inventing
payment processing that doesn't exist: `billing_invoices.due_date`/`status`
(`BillingInvoice::isOverdue()` already implemented), `billing_payments.status`
(`failed` is a real value), and `billing_alerts.type` (`invoice_due`/
`payment_failed` already named in the column's own schema comment).

## Goal

Build the Level 1 piece the audit names (raise alerts, fire the two existing
notifications, on a schedule) and gate the one real decision this domain
contains behind human sign-off: what to do about a team whose payment failed
or invoice went unpaid isn't something the system should decide — leniency,
retry, or cutting off access all carry real consequences for that team's
business, and (unlike Dot.Auction's seller-decides-their-own-reserve case)
the delinquent team itself is the wrong party to decide its own grace period.

## Design

### 1. `is_platform_operator` flag

Boolean on `users`, default `false`, excluded from `$fillable`. Identical to
the established pattern already used on Ehail/Emall/Files/Press/Sheet/Tutor
when no existing role fits. No existing role in this schema fits here: teams
are customers, not Dot.Billing staff, so the operator concept must be
invented fresh, exactly as it was on those five other platforms. Provisioned
by hand (`DB::table('users')->where(...)->update(['is_platform_operator' =>
true])` or a direct edit), never through app code — matching every prior use
of this pattern in this program.

### 2. `dunning_cases` table + `DunningCase` model

| Column | Type | Notes |
|---|---|---|
| `team_id` | FK → `teams`, cascade delete | the delinquent team |
| `invoice_id` | nullable FK → `billing_invoices`, cascade delete | set when opened from an overdue invoice |
| `payment_id` | nullable FK → `billing_payments`, cascade delete | set when opened from a failed payment |
| `reason` | string | `invoice_overdue` \| `payment_failed` |
| `status` | string, default `open` | `open` / `extended` / `canceled` / `dismissed` |
| `resolved_at` | nullable timestamp | |
| `resolved_by` | nullable FK → `users`, `nullOnDelete` | the operator who decided |
| `resolution_notes` | nullable text | |
| timestamps | | |

**Deliberately does not use `HasTeamScope`** — every other model in this
codebase (`BillingInvoice`, `BillingPayment`, `BillingSubscription`,
`BillingAlert`) carries that trait, which globally scopes every query to
`Auth::user()->currentTeam->id`. A platform operator reviewing a delinquent
team's case is not a member of that team; applying the trait here would
silently hide every case from the one person who needs to see them across
every team. Same reasoning already applied to Dot.Agents'
`RetentionPurgeProposal` in this program.

```php
class DunningCase extends Model
{
    protected $fillable = [
        'team_id', 'invoice_id', 'payment_id', 'reason', 'status',
        'resolved_at', 'resolved_by', 'resolution_notes',
    ];

    protected $casts = ['resolved_at' => 'datetime'];

    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(BillingInvoice::class); }
    public function payment(): BelongsTo { return $this->belongsTo(BillingPayment::class); }
    public function resolver(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
}
```

### 3. `app/Console/Commands/ScanBillingAlerts.php` (`billing:scan-alerts`)

This platform's first scheduled command:

```
for each BillingInvoice where status = 'open':
    if invoice.isOverdue():                          # existing method, reused
        if no DunningCase already exists for this invoice_id:
            DunningCase::create(team_id, invoice_id, reason: 'invoice_overdue', status: 'open')
            ensure a BillingAlert(team_id, type: 'invoice_due', status: 'active') exists
                (skip creating a duplicate if one is already active for the team)
            team.owner.notify(InvoiceDueNotification(invoice))

for each BillingPayment where status = 'failed':
    if no DunningCase already exists for this payment_id:
        DunningCase::create(team_id, invoice_id: payment.invoice_id, payment_id: payment.id,
                             reason: 'payment_failed', status: 'open')
        ensure a BillingAlert(team_id, type: 'payment_failed', status: 'active') exists
        team.owner.notify(PaymentFailedNotification(payment))
```

Dedup is keyed on real foreign keys (`invoice_id`/`payment_id` on
`DunningCase` — one case per underlying overdue invoice or failed payment,
never duplicated on a later run), while `BillingAlert` dedup stays coarse
(one active alert per team per type, matching the existing schema's
per-team granularity — it has no `invoice_id`/`payment_id` column and this
spec doesn't add one, since the alert is a team-level signal and the
`DunningCase` is the per-incident record).

Each row processed inside its own try/catch — one bad row is logged and
skipped, not allowed to abort the whole scan (matches
`DetectRetentionPurgeCandidates`'s established per-row resilience
convention).

Scheduled in `routes/console.php` — this platform's first `Schedule::` entry
— `->everyFifteenMinutes()->withoutOverlapping()`.

### 4. `app/Policies/DunningCasePolicy.php`

```php
public function review(User $user, DunningCase $case): bool
{
    return (bool) $user->is_platform_operator;
}
```

### 5. `App\Livewire\Billing\DunningQueue`

Mirrors `RetentionPurgeQueue`'s list-style shape (this program's established
pattern for operator-console review screens, as distinct from Dot.Auction's
per-item embedded panel — that one was customer-facing on the seller's own
dashboard; this one is internal operator tooling reviewing across every
team, so a single queue listing every open case fits better).

```php
public function extend(int $id, int $days): void
{
    Gate::authorize('review', $case = DunningCase::findOrFail($id));

    $case->invoice?->update(['due_date' => now()->addDays($days)]);
    $case->update(['status' => 'extended', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);
}

public function cancelSubscription(int $id): void
{
    Gate::authorize('review', $case = DunningCase::findOrFail($id));

    $case->team->subscription?->update(['status' => 'canceled', 'canceled_at' => now()]);
    $case->update(['status' => 'canceled', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);
}
```

A third path — `dismiss($id)` — closes a case with no state change (e.g. the
team paid outside the system and the operator just needs to clear it),
matching the "defer/dismiss" option every review queue in this program has
had:

```php
public function dismiss(int $id): void
{
    Gate::authorize('review', $case = DunningCase::findOrFail($id));

    $case->update(['status' => 'dismissed', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);
}
```

### 6. Routes

New `operator` middleware alias (`App\Http\Middleware\EnsurePlatformOperator`,
identical to Dot.Tutor's implementation — `abort_unless($request->user()?->is_platform_operator, 403);`)
registered in `bootstrap/app.php`. New route group:

```php
Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
    Route::get('/dunning-cases', fn () => view('operator.dunning-cases'))->name('dunning-cases.index');
});
```

Matches Dot.Tutor's exact `operator` alias / `/operator` prefix / `operator.`
route-name-prefix convention.

## Testing Strategy

- `tests/Feature/Console/ScanBillingAlertsCommandTest.php`: an overdue open
  invoice opens exactly one case and fires the notification; a second run
  doesn't duplicate the case or re-notify; a failed payment opens a case and
  fires its notification; a non-overdue open invoice and a succeeded payment
  are both left untouched.
- `tests/Feature/Livewire/DunningQueueTest.php`: a platform operator can
  extend (invoice `due_date` pushed, case `extended`), can cancel
  (subscription `canceled`, case `canceled`), can dismiss (case `dismissed`,
  nothing else changes); a non-operator is forbidden from all three.

## Out of Scope

- Any real Stripe retry/charge logic — this schema has no live payment
  processing to retry against.
- `DunningQueue`'s Blade view visual polish beyond matching this repo's
  existing dashboard styling convention.
- The `finance.dunning.opened`/`finance.dunning.closed` *outbound* domain
  events to Dot.Brain (wiki.md §5's own framing: events don't exist in this
  codebase at all yet, DKP publishing is a separate, larger unbuilt piece
  per §8's roadmap) — this spec builds the internal state machine those
  events would eventually announce, not the publishing pipeline itself.
- Any change to `AiBillingService` or the existing three Livewire dashboard
  components (`BillingOverview`, `InvoiceTable`, `UsageDashboard`) — the
  Cushion-program `PaymentReliability` component (already shipped, separate
  work) is likewise untouched.
