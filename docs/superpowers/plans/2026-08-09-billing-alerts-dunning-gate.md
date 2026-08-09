# Billing Alert Scanning + Dunning Approval Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build this platform's first-ever scheduled process — a billing alert scan — split so raising alerts and firing the two already-written notifications happens automatically (Level 1), while deciding what to do about a delinquent team (extend, cancel, or dismiss) stops for a platform operator's sign-off (Level 2).

**Architecture:** A new `billing:scan-alerts` console command (this repo's first scheduled job) finds overdue open invoices and failed payments, opens a `DunningCase` per underlying incident (deduped on real foreign keys), raises the matching `BillingAlert` (deduped per team+type), and fires `InvoiceDueNotification`/`PaymentFailedNotification` to the team owner. A new `DunningQueue` Livewire component (mirroring `RetentionPurgeQueue`'s list shape from the Dot.Agents work in this same program) lets a `is_platform_operator`-flagged user — a brand-new flag, since no existing role in this schema fits a Dot.Billing staff member — extend the grace period, cancel the subscription, or dismiss the case.

**Tech Stack:** Laravel 13 (pgsql in production, sqlite in tests), Livewire, PHPUnit.

## Global Constraints

- `DunningCase` does **not** use the `HasTeamScope` trait every other Billing model has — a platform operator reviewing a delinquent team's case is not a member of that team, and the trait's global scope would hide every case from them. Do not add it.
- Reuse `BillingInvoice::isOverdue()` (existing method) for the overdue check — do not re-derive `status === 'open' && due_date->isPast()` separately.
- The `is_platform_operator` flag: boolean on `users`, default `false`, **excluded** from `$fillable` (never mass-assignable), added to the `casts()` method as `'boolean'`. Provisioned only by hand in tests/ops, never through app code — identical to the Ehail/Emall/Files/Press/Sheet/Tutor precedent.
- `operator` middleware alias → `App\Http\Middleware\EnsurePlatformOperator`, registered in `bootstrap/app.php`'s `withMiddleware()` closure — identical to Dot.Tutor's exact implementation (`abort_unless($request->user()?->is_platform_operator, 403);`).
- `Gate::authorize('review', $case)` as the first line of every `DunningQueue` mutating method.
- Tests use `Model::create()` directly (no factories for `BillingInvoice`/`BillingPayment`/`BillingAlert`/`DunningCase` exist, and none are introduced here) — matches `tests/Feature/Billing/BillingTest.php`'s established convention exactly. `User::factory()->withPersonalTeam()->create()` for acting users.
- Styling for the new operator view: `<x-app-layout>` wrapper + the existing `.dot-card` CSS class with inline styles, matching `resources/views/dashboard.blade.php`'s established convention exactly (dark theme, `'Syne'` display font, `#f4f4f5`/`#52525b` text colors).
- Per this repo's own `CLAUDE.md` Laravel Boost guidelines ("Verification Scripts" section): do not create verification scripts or use `tinker` when tests cover the functionality and prove it works.
- Run `vendor/bin/pint --dirty --format agent` after every task before committing.

---

### Task 1: `is_platform_operator` flag + `dunning_cases` table + `DunningCase` model

**Files:**
- Create: `database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php`
- Create: `database/migrations/2026_08_09_000002_create_dunning_cases_table.php`
- Modify: `app/Models/User.php` (add `'is_platform_operator' => 'boolean'` to `casts()`)
- Create: `app/Models/DunningCase.php`
- Test: `tests/Unit/Models/DunningCaseTest.php`

**Interfaces:**
- Produces: `users.is_platform_operator` (bool, default false); `DunningCase` model, `$fillable = ['team_id', 'invoice_id', 'payment_id', 'reason', 'status', 'resolved_at', 'resolved_by', 'resolution_notes']`, relations `team()`, `invoice()`, `payment()`, `resolver()`.

- [x] **Step 1: Write the `is_platform_operator` migration**

Create `database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_operator')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_operator');
        });
    }
};
```

- [x] **Step 2: Write the `dunning_cases` migration**

Create `database/migrations/2026_08_09_000002_create_dunning_cases_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('billing_payments')->cascadeOnDelete();
            $table->string('reason');
            $table->string('status')->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_cases');
    }
};
```

- [x] **Step 3: Run migrations**

Run: `php artisan migrate`
Expected: both migrations run without error.

- [x] **Step 4: Add the cast to `User`**

In `app/Models/User.php`, inside the existing `casts(): array` method, add
`'is_platform_operator' => 'boolean',` to the returned array. Do **not** add
`is_platform_operator` to `$fillable`.

- [x] **Step 5: Write the `DunningCase` model**

Create `app/Models/DunningCase.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DunningCase extends Model
{
    protected $fillable = [
        'team_id', 'invoice_id', 'payment_id', 'reason', 'status',
        'resolved_at', 'resolved_by', 'resolution_notes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
```

- [x] **Step 6: Write a model test**

Create `tests/Unit/Models/DunningCaseTest.php`:

```php
<?php

namespace Tests\Unit\Models;

use App\Models\BillingInvoice;
use App\Models\DunningCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DunningCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_a_team_an_invoice_and_a_resolver(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $operator = User::factory()->create(['is_platform_operator' => true]);
        $team = $owner->currentTeam;

        $invoice = BillingInvoice::create([
            'team_id' => $team->id,
            'invoice_number' => 'INV-100',
            'status' => 'open',
            'total' => 500,
            'due_date' => now()->subDays(5),
        ]);

        $case = DunningCase::create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'reason' => 'invoice_overdue',
            'status' => 'open',
        ]);

        $this->assertTrue($case->team->is($team));
        $this->assertTrue($case->invoice->is($invoice));
        $this->assertNull($case->resolver);

        $case->update(['status' => 'extended', 'resolved_at' => now(), 'resolved_by' => $operator->id]);
        $this->assertTrue($case->fresh()->resolver->is($operator));
    }

    public function test_a_platform_operator_is_visible_across_teams(): void
    {
        // Regression guard: DunningCase must NOT use HasTeamScope. If it
        // did, this query (run with no acting user / no currentTeam) would
        // still work in this specific test, but the real risk is an
        // operator with their OWN currentTeam seeing only their own team's
        // cases -- assert the model has no such scope by checking two
        // different teams' cases are both visible in one unscoped query.
        $ownerA = User::factory()->withPersonalTeam()->create();
        $ownerB = User::factory()->withPersonalTeam()->create();

        DunningCase::create(['team_id' => $ownerA->currentTeam->id, 'reason' => 'payment_failed', 'status' => 'open']);
        DunningCase::create(['team_id' => $ownerB->currentTeam->id, 'reason' => 'payment_failed', 'status' => 'open']);

        $this->actingAs($ownerA);

        $this->assertSame(2, DunningCase::count());
    }
}
```

- [x] **Step 7: Run the tests**

Run: `php artisan test --compact tests/Unit/Models/DunningCaseTest.php`
Expected: PASS, 2 tests.

- [x] **Step 8: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [x] **Step 9: Commit**

```bash
git add database/migrations/2026_08_09_000001_add_is_platform_operator_to_users_table.php \
  database/migrations/2026_08_09_000002_create_dunning_cases_table.php \
  app/Models/User.php app/Models/DunningCase.php \
  tests/Unit/Models/DunningCaseTest.php
git commit -m "$(cat <<'EOF'
feat: is_platform_operator flag + dunning_cases table + DunningCase model

Adds the is_platform_operator flag (same pattern already used on
Ehail/Emall/Files/Press/Sheet/Tutor when no existing role fits) and
dunning_cases, the record opened when an invoice goes overdue or a
payment fails -- see
docs/superpowers/specs/2026-08-09-billing-alerts-dunning-gate.md.

DunningCase deliberately skips HasTeamScope (every other Billing
model has it) -- a platform operator reviewing a delinquent team's
case isn't a member of that team, and the trait's scope would hide
every case from them.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `billing:scan-alerts` command + schedule

**Files:**
- Create: `app/Console/Commands/ScanBillingAlerts.php`
- Modify: `routes/console.php` (add the schedule entry)
- Test: `tests/Feature/Console/ScanBillingAlertsCommandTest.php`

**Interfaces:**
- Consumes: `BillingInvoice::isOverdue()` (existing), `Team::owner` (Jetstream's base `Team` relation), `DunningCase::create()` (Task 1), `App\Notifications\InvoiceDueNotification`/`PaymentFailedNotification` (existing).
- Produces: Artisan command `billing:scan-alerts`.

- [x] **Step 1: Write the failing command test**

Create `tests/Feature/Console/ScanBillingAlertsCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\DunningCase;
use App\Models\User;
use App\Notifications\InvoiceDueNotification;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ScanBillingAlertsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_overdue_open_invoice_opens_a_case_and_notifies_the_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $invoice = BillingInvoice::create([
            'team_id' => $owner->currentTeam->id,
            'invoice_number' => 'INV-200',
            'status' => 'open',
            'total' => 500,
            'due_date' => now()->subDays(3),
        ]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertDatabaseHas('dunning_cases', [
            'team_id' => $owner->currentTeam->id,
            'invoice_id' => $invoice->id,
            'reason' => 'invoice_overdue',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('billing_alerts', [
            'team_id' => $owner->currentTeam->id,
            'type' => 'invoice_due',
            'status' => 'active',
        ]);
        Notification::assertSentTo($owner, InvoiceDueNotification::class);
    }

    public function test_running_it_twice_does_not_duplicate_the_case_or_renotify(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        BillingInvoice::create([
            'team_id' => $owner->currentTeam->id,
            'invoice_number' => 'INV-201',
            'status' => 'open',
            'total' => 500,
            'due_date' => now()->subDays(3),
        ]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();
        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertDatabaseCount('dunning_cases', 1);
        Notification::assertSentToTimes($owner, InvoiceDueNotification::class, 1);
    }

    public function test_a_failed_payment_opens_a_case_and_notifies_the_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $invoice = BillingInvoice::create([
            'team_id' => $owner->currentTeam->id,
            'invoice_number' => 'INV-202',
            'status' => 'open',
            'total' => 300,
            'due_date' => now()->addDays(10),
        ]);
        $payment = BillingPayment::create([
            'team_id' => $owner->currentTeam->id,
            'invoice_id' => $invoice->id,
            'amount' => 300,
            'status' => 'failed',
            'failure_reason' => 'card_declined',
        ]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertDatabaseHas('dunning_cases', [
            'team_id' => $owner->currentTeam->id,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'reason' => 'payment_failed',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('billing_alerts', [
            'team_id' => $owner->currentTeam->id,
            'type' => 'payment_failed',
        ]);
        Notification::assertSentTo($owner, PaymentFailedNotification::class);
    }

    public function test_a_non_overdue_open_invoice_and_a_succeeded_payment_are_untouched(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        BillingInvoice::create([
            'team_id' => $owner->currentTeam->id,
            'invoice_number' => 'INV-203',
            'status' => 'open',
            'total' => 100,
            'due_date' => now()->addDays(10),
        ]);
        BillingPayment::create([
            'team_id' => $owner->currentTeam->id,
            'amount' => 100,
            'status' => 'succeeded',
        ]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertSame(0, DunningCase::count());
    }
}
```

- [x] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Console/ScanBillingAlertsCommandTest.php`
Expected: FAIL — command `billing:scan-alerts` does not exist yet.

- [x] **Step 3: Write the command**

Create `app/Console/Commands/ScanBillingAlerts.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\BillingAlert;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\DunningCase;
use App\Notifications\InvoiceDueNotification;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Console\Command;

class ScanBillingAlerts extends Command
{
    protected $signature = 'billing:scan-alerts';

    protected $description = 'Raise alerts for overdue invoices and failed payments, and open a dunning case for a platform operator to review; never suspends or cancels anything on its own.';

    public function handle(): int
    {
        $opened = 0;

        foreach (BillingInvoice::where('status', 'open')->get() as $invoice) {
            try {
                if ($invoice->isOverdue() && ! DunningCase::where('invoice_id', $invoice->id)->exists()) {
                    $this->openCase($invoice->team_id, invoiceId: $invoice->id, paymentId: null, reason: 'invoice_overdue');
                    $this->ensureAlert($invoice->team_id, 'invoice_due');
                    $invoice->team->owner?->notify(new InvoiceDueNotification($invoice));
                    $opened++;
                }
            } catch (\Throwable $e) {
                $this->error("Failed to process invoice #{$invoice->id}: {$e->getMessage()}");
            }
        }

        foreach (BillingPayment::where('status', 'failed')->get() as $payment) {
            try {
                if (! DunningCase::where('payment_id', $payment->id)->exists()) {
                    $this->openCase($payment->team_id, invoiceId: $payment->invoice_id, paymentId: $payment->id, reason: 'payment_failed');
                    $this->ensureAlert($payment->team_id, 'payment_failed');
                    $payment->team->owner?->notify(new PaymentFailedNotification($payment));
                    $opened++;
                }
            } catch (\Throwable $e) {
                $this->error("Failed to process payment #{$payment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Opened {$opened} dunning case(s).");

        return self::SUCCESS;
    }

    private function openCase(int $teamId, ?int $invoiceId, ?int $paymentId, string $reason): void
    {
        DunningCase::create([
            'team_id' => $teamId,
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'reason' => $reason,
            'status' => 'open',
        ]);
    }

    private function ensureAlert(int $teamId, string $type): void
    {
        $exists = BillingAlert::where('team_id', $teamId)
            ->where('type', $type)
            ->where('status', 'active')
            ->exists();

        if (! $exists) {
            BillingAlert::create([
                'team_id' => $teamId,
                'type' => $type,
                'status' => 'active',
                'triggered_at' => now(),
            ]);
        }
    }
}
```

- [x] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Console/ScanBillingAlertsCommandTest.php`
Expected: PASS, 4 tests, 0 failures.

- [x] **Step 5: Add the schedule entry**

`routes/console.php` currently has only the stock `inspire` command. Add:

```php
use App\Console\Commands\ScanBillingAlerts;
use Illuminate\Support\Facades\Schedule;

// ─── Scheduled Platform Jobs ──────────────────────────────────────────────────
// This platform's first scheduled process -- see
// docs/superpowers/specs/2026-08-09-billing-alerts-dunning-gate.md.
Schedule::command(ScanBillingAlerts::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
```

- [x] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [x] **Step 7: Commit**

```bash
git add app/Console/Commands/ScanBillingAlerts.php routes/console.php \
  tests/Feature/Console/ScanBillingAlertsCommandTest.php
git commit -m "$(cat <<'EOF'
feat: billing:scan-alerts command -- this platform's first scheduler entry

Raises invoice_due/payment_failed BillingAlerts and fires the two
existing notifications (InvoiceDueNotification, PaymentFailedNotification
-- previously written but never wired to any trigger), the exact
Level 1 step the audit's own gap summary named. Opens a DunningCase
per overdue invoice or failed payment, deduped on real foreign keys
so a second run never duplicates a case or re-notifies. Never changes
subscription status or invoice due dates itself -- that's the
operator's decision (Task 3).

Scheduled every 15 minutes in routes/console.php, this platform's
first Schedule:: entry.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `DunningCasePolicy` + operator middleware + `DunningQueue` + routes

**Files:**
- Create: `app/Policies/DunningCasePolicy.php`
- Create: `app/Http/Middleware/EnsurePlatformOperator.php`
- Modify: `bootstrap/app.php` (register the `operator` middleware alias)
- Create: `app/Livewire/Billing/DunningQueue.php`
- Create: `resources/views/livewire/billing/dunning-queue.blade.php`
- Create: `resources/views/operator/dunning-cases.blade.php`
- Modify: `routes/web.php` (add the `operator` route group)
- Test: `tests/Feature/Livewire/DunningQueueTest.php`

**Interfaces:**
- Consumes: `DunningCase` (Task 1).
- Produces: `Gate`-checkable ability `review` on `DunningCase`; `operator` middleware alias; Livewire component `billing.dunning-queue`; route `operator.dunning-cases.index`.

- [x] **Step 1: Write the failing Livewire test**

Create `tests/Feature/Livewire/DunningQueueTest.php`:

```php
<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Billing\DunningQueue;
use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\DunningCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DunningQueueTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->create(['is_platform_operator' => true]);
    }

    private function makeCase(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $plan = BillingPlan::create(['name' => 'Pro', 'slug' => 'pro-'.$team->id, 'price_monthly' => 299]);
        $subscription = BillingSubscription::create([
            'team_id' => $team->id, 'plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => 'monthly',
        ]);
        $invoice = BillingInvoice::create([
            'team_id' => $team->id, 'invoice_number' => 'INV-300', 'status' => 'open',
            'total' => 500, 'due_date' => now()->subDays(5),
        ]);
        $case = DunningCase::create([
            'team_id' => $team->id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open',
        ]);

        return compact('team', 'subscription', 'invoice', 'case');
    }

    public function test_an_operator_can_extend_the_grace_period(): void
    {
        ['invoice' => $invoice, 'case' => $case] = $this->makeCase();
        $originalDueDate = $invoice->due_date;

        Livewire::actingAs($this->operator())
            ->test(DunningQueue::class)
            ->call('extend', $case->id, 7);

        $this->assertTrue($invoice->fresh()->due_date->greaterThan($originalDueDate));
        $this->assertSame('extended', $case->fresh()->status);
        $this->assertNotNull($case->fresh()->resolved_at);
    }

    public function test_an_operator_can_cancel_the_subscription(): void
    {
        ['subscription' => $subscription, 'case' => $case] = $this->makeCase();

        Livewire::actingAs($this->operator())
            ->test(DunningQueue::class)
            ->call('cancelSubscription', $case->id);

        $this->assertSame('canceled', $subscription->fresh()->status);
        $this->assertSame('canceled', $case->fresh()->status);
    }

    public function test_an_operator_can_dismiss_a_case(): void
    {
        ['case' => $case, 'invoice' => $invoice] = $this->makeCase();
        $originalDueDate = $invoice->due_date;

        Livewire::actingAs($this->operator())
            ->test(DunningQueue::class)
            ->call('dismiss', $case->id);

        $this->assertSame('dismissed', $case->fresh()->status);
        $this->assertEquals($originalDueDate, $invoice->fresh()->due_date);
    }

    public function test_a_non_operator_is_forbidden(): void
    {
        ['case' => $case] = $this->makeCase();
        $regularUser = User::factory()->create();

        Livewire::actingAs($regularUser)
            ->test(DunningQueue::class)
            ->call('extend', $case->id, 7)
            ->assertForbidden();

        $this->assertSame('open', $case->fresh()->status);
    }
}
```

- [x] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Livewire/DunningQueueTest.php`
Expected: FAIL — `App\Livewire\Billing\DunningQueue` doesn't exist.

- [x] **Step 3: Write the policy**

Create `app/Policies/DunningCasePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\DunningCase;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DunningCasePolicy
{
    use HandlesAuthorization;

    public function review(User $user, DunningCase $case): bool
    {
        return (bool) $user->is_platform_operator;
    }
}
```

- [x] **Step 4: Write the middleware**

Create `app/Http/Middleware/EnsurePlatformOperator.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_platform_operator, 403);

        return $next($request);
    }
}
```

- [x] **Step 5: Register the middleware alias**

In `bootstrap/app.php`, add `use App\Http\Middleware\EnsurePlatformOperator;`
to the top import block, and inside the existing (currently empty)
`->withMiddleware(function (Middleware $middleware): void { ... })` closure:

```php
$middleware->alias([
    'operator' => EnsurePlatformOperator::class,
]);
```

- [x] **Step 6: Write the Livewire component**

Create `app/Livewire/Billing/DunningQueue.php`:

```php
<?php

namespace App\Livewire\Billing;

use App\Models\DunningCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DunningQueue extends Component
{
    #[Computed]
    public function cases(): Collection
    {
        return DunningCase::where('status', 'open')
            ->with(['team', 'invoice', 'payment'])
            ->orderBy('created_at')
            ->get();
    }

    public function extend(int $id, int $days): void
    {
        $case = DunningCase::findOrFail($id);
        Gate::authorize('review', $case);

        $case->invoice?->update(['due_date' => now()->addDays($days)]);
        $case->update(['status' => 'extended', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);

        unset($this->cases);
    }

    public function cancelSubscription(int $id): void
    {
        $case = DunningCase::findOrFail($id);
        Gate::authorize('review', $case);

        $case->team->subscription?->update(['status' => 'canceled', 'canceled_at' => now()]);
        $case->update(['status' => 'canceled', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);

        unset($this->cases);
    }

    public function dismiss(int $id): void
    {
        $case = DunningCase::findOrFail($id);
        Gate::authorize('review', $case);

        $case->update(['status' => 'dismissed', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);

        unset($this->cases);
    }

    public function render(): View
    {
        return view('livewire.billing.dunning-queue');
    }
}
```

- [x] **Step 6a (discovered via TDD, not in the original draft): fix a real
  cross-team-visibility bug**

Running the Step 1 test against this Step 6 draft (Step 10, run early to
check progress) produced 2 real failures, not the expected pass: `extend()`
silently didn't change `due_date`, and `cancelSubscription()` silently
didn't change the subscription's `status`. Traced via the SQL log rather
than guessed: `BillingInvoice`/`BillingPayment`/`BillingSubscription` all
carry `HasTeamScope`, and this repo's actual implementation of that trait
**fails closed** — `Auth::check() && ! Auth::user()->currentTeam` adds a
`1 = 0` clause (see `app/Models/Concerns/HasTeamScope.php`), specifically to
stop an authenticated-but-teamless request from seeing every team's rows. A
`platform_operator` is exactly that case by design (Global Constraints and
the spec's Design §2 both said "not a member of that team" — the
implication that they're also *teamless*, and what that does to every
relation `DunningCase` traverses into, wasn't traced through at spec time).

Fix: `DunningCase::invoice()`/`::payment()` (Task 1's model) now call
`->withoutGlobalScope('team')`, and `cancelSubscription()` below queries
`BillingSubscription` directly with the same bypass instead of traversing
`$case->team->subscription`. Re-apply Task 1's `app/Models/DunningCase.php`
with this change:

```php
public function invoice(): BelongsTo
{
    return $this->belongsTo(BillingInvoice::class)->withoutGlobalScope('team');
}

public function payment(): BelongsTo
{
    return $this->belongsTo(BillingPayment::class)->withoutGlobalScope('team');
}
```

And replace `cancelSubscription()` in the component above with:

```php
public function cancelSubscription(int $id): void
{
    $case = DunningCase::findOrFail($id);
    Gate::authorize('review', $case);

    BillingSubscription::withoutGlobalScope('team')
        ->where('team_id', $case->team_id)
        ->first()
        ?->update(['status' => 'canceled', 'canceled_at' => now()]);

    $case->update(['status' => 'canceled', 'resolved_at' => now(), 'resolved_by' => auth()->id()]);

    unset($this->cases);
}
```

(add `use App\Models\BillingSubscription;` to the component's imports).
`ScanBillingAlerts` (Task 2) is unaffected — it runs via Artisan with no
authenticated user at all, so `HasTeamScope`'s condition never reaches the
fail-closed branch there.

- [x] **Step 7: Write the Blade view**

Create `resources/views/livewire/billing/dunning-queue.blade.php`, matching
the `.dot-card` dark-theme convention from `resources/views/dashboard.blade.php`:

```blade
<div>
    @if ($this->cases->isEmpty())
        <div class="dot-card" style="padding:1.5rem;text-align:center;color:#52525b;font-size:0.85rem;">
            No open dunning cases.
        </div>
    @endif

    @foreach ($this->cases as $case)
        <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <div style="font-family:'Syne',sans-serif;font-weight:700;color:#f4f4f5;">{{ $case->team->name }}</div>
                    <div style="font-size:0.78rem;color:#52525b;margin-top:0.2rem;">
                        {{ $case->reason === 'invoice_overdue' ? 'Invoice overdue' : 'Payment failed' }}
                        @if($case->invoice) — {{ $case->invoice->invoice_number }} (R{{ number_format((float) $case->invoice->total, 2) }}) @endif
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:0.5rem;margin-top:0.9rem;">
                <button wire:click="extend({{ $case->id }}, 7)" wire:confirm="Extend the grace period by 7 days?"
                    style="font-size:0.72rem;font-weight:600;padding:0.35rem 0.85rem;border-radius:9999px;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.35);color:#4ade80;cursor:pointer;">
                    Extend 7 days
                </button>
                <button wire:click="cancelSubscription({{ $case->id }})" wire:confirm="Cancel this team's subscription?"
                    style="font-size:0.72rem;font-weight:600;padding:0.35rem 0.85rem;border-radius:9999px;background:rgba(244,63,94,0.1);border:1px solid rgba(244,63,94,0.3);color:#fb7185;cursor:pointer;">
                    Cancel subscription
                </button>
                <button wire:click="dismiss({{ $case->id }})"
                    style="font-size:0.72rem;font-weight:600;padding:0.35rem 0.85rem;border-radius:9999px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#a1a1aa;cursor:pointer;">
                    Dismiss
                </button>
            </div>
        </div>
    @endforeach
</div>
```

- [x] **Step 8: Write the page wrapper view**

Create `resources/views/operator/dunning-cases.blade.php`:

```blade
<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 1.5rem;letter-spacing:-0.01em;">Dunning Cases</h1>
    <livewire:billing.dunning-queue />
</div>
</x-app-layout>
```

- [x] **Step 9: Add the route**

In `routes/web.php`, inside the existing authenticated middleware group
(after the `/invoices/{invoice}` route), add:

```php
Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
    Route::get('/dunning-cases', fn () => view('operator.dunning-cases'))->name('dunning-cases.index');
});
```

- [x] **Step 10: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Livewire/DunningQueueTest.php`
Expected: PASS, 4 tests, 0 failures.

- [x] **Step 11: Manual verification**

Per this repo's own no-tinker rule, do not verify with `tinker` or a
throwaway script — the Livewire test above already exercises this. Skip
manual verification.

- [x] **Step 12: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 13: Commit**

```bash
git add app/Policies/DunningCasePolicy.php \
  app/Http/Middleware/EnsurePlatformOperator.php bootstrap/app.php \
  app/Livewire/Billing/DunningQueue.php \
  resources/views/livewire/billing/dunning-queue.blade.php \
  resources/views/operator/dunning-cases.blade.php \
  routes/web.php \
  tests/Feature/Livewire/DunningQueueTest.php
git commit -m "$(cat <<'EOF'
feat: platform-operator dunning review queue

DunningQueue (mirrors RetentionPurgeQueue's list shape from the
Dot.Agents work in this program) lets a platform operator extend a
delinquent team's grace period, cancel their subscription, or
dismiss the case -- gated by the new DunningCasePolicy and an
operator middleware alias (EnsurePlatformOperator, identical to
Dot.Tutor's implementation). The system itself never takes any of
these three actions on its own; ScanBillingAlerts (previous commit)
only ever opens a case.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Full regression

**Files:** none (verification only).

- [x] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Actual: PASS, 92 tests, 85 passed, 7 skipped, 0 failures.

- [x] **Step 2: Run Pint across the whole repo**

Run: `vendor/bin/pint --format agent`
Actual: `passed`.

- [x] **Step 3: Report**

Report the final test count and confirm the working tree is clean
(`git status --short`). No manual tinker verification, per this repo's own
Laravel Boost guideline — the test suite (Tasks 1-3) already proves the
scan and review flows work.

---

## Self-Review Notes

- **Spec coverage:** §1 (`is_platform_operator`) → Task 1 Steps 1, 4. §2
  (`dunning_cases` + model) → Task 1 Steps 2, 5. §3 (scan command) → Task 2
  Step 3, schedule → Task 2 Step 5. §4 (policy) → Task 3 Step 3. §5
  (`DunningQueue`, including the `dismiss` method added during the spec's
  own self-review) → Task 3 Step 6. §6 (routes/middleware) → Task 3 Steps
  4-5, 9. Testing Strategy → Task 1 Step 6, Task 2 Step 1, Task 3 Step 1.
  All spec sections have a task.
- **Placeholder scan:** none found — every step has complete code.
- **Type consistency:** `DunningCase::$fillable` (Task 1 Step 5) matches
  every `::create()`/`::update()` call across Tasks 1-3 exactly.
  `DunningQueue::extend(int $id, int $days)` matches its dispatch site in
  the Blade view (`extend({{ $case->id }}, 7)`) and its test call
  (`->call('extend', $case->id, 7)`). `Team::owner` (used in Task 2's
  command) is Jetstream's own base `Team` model relation, not redefined
  anywhere in this app's `Team.php` — confirmed by reading
  `app/Models/Team.php` directly (it only adds `subscription()`,
  `invoices()`, `payments()`, `billingAlerts()` on top of the Jetstream
  base, `owner()` comes from the parent class unchanged).
