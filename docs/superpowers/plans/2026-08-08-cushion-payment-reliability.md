# Dot.Billing: Cushion — Payment Reliability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show each team their real on-time payment rate over a trailing 6-month window, on the existing billing dashboard — honestly, including when there's nothing to compute yet.

**Architecture:** A new `PaymentReliabilityCalculator` service (pure computation, testable in isolation) is called from a new Livewire component, `PaymentReliability`, mirroring the existing `BillingOverview` component's structure. Rendered on `resources/views/dashboard.blade.php`.

**Tech Stack:** Laravel 12 (existing app), Livewire 3 (existing), PHPUnit — no new dependencies.

## Global Constraints

- Eligible invoices (trailing 6 months by `due_date`): `status = 'paid'`, OR (`status = 'open'` AND `due_date < now()`), OR `status = 'uncollectible'` — excludes `draft`, `void`, and not-yet-due `open` invoices (per spec's Computation section).
- On-time = eligible invoice with `status = 'paid'` AND `paid_at <= due_date`.
- Zero eligible invoices → section omitted entirely, never shown as 0% (per spec's Computation section).
- The what-if never implies a late-paid invoice becomes retroactively "on time" — it's framed as "if currently-overdue invoices were resolved" (per spec's honesty note).
- Team-scoping (`HasTeamScope`) applies automatically to all queries — no manual `team_id` filtering needed or wanted.

---

### Task 1: `PaymentReliabilityCalculator` service

**Files:**
- Create: `app/Services/PaymentReliabilityCalculator.php`
- Test: `tests/Unit/PaymentReliabilityCalculatorTest.php`

**Interfaces:**
- Consumes: `App\Models\BillingInvoice` (existing, team-scoped via `HasTeamScope`).
- Produces: `PaymentReliabilityCalculator::calculate(): array` (returns the contract shape from the spec: `available`, `reason`, `eligible_invoice_count`, `on_time_count`, `on_time_rate_pct`, `basis`, `what_if`) — consumed by Task 2's Livewire component. No `$teamId` parameter needed — `HasTeamScope` applies automatically based on the authenticated user's current team, matching how `BillingOverview`'s existing queries work.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit;

use App\Models\BillingInvoice;
use App\Models\Team;
use App\Models\User;
use App\Services\PaymentReliabilityCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReliabilityCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTeamMember(): Team
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        return $team;
    }

    private function createInvoice(Team $team, string $status, ?\DateTimeInterface $dueDate, ?\DateTimeInterface $paidAt = null): BillingInvoice
    {
        return BillingInvoice::create([
            'team_id' => $team->id,
            'invoice_number' => 'INV-' . uniqid(),
            'status' => $status,
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
            'currency' => 'USD',
            'due_date' => $dueDate,
            'paid_at' => $paidAt,
        ]);
    }

    public function test_eligible_invoices_exclude_draft_void_and_not_yet_due_open(): void
    {
        $team = $this->actingAsTeamMember();

        $this->createInvoice($team, 'draft', now()->addDays(10)); // excluded
        $this->createInvoice($team, 'void', now()->subDays(5)); // excluded
        $this->createInvoice($team, 'open', now()->addDays(10)); // not yet due -- excluded
        $this->createInvoice($team, 'paid', now()->subDays(5), now()->subDays(6)); // eligible, on-time

        $result = (new PaymentReliabilityCalculator())->calculate();

        $this->assertSame(1, $result['eligible_invoice_count']);
    }

    public function test_on_time_rate_computed_correctly(): void
    {
        $team = $this->actingAsTeamMember();

        // On-time: paid before due date.
        $this->createInvoice($team, 'paid', now()->subDays(10), now()->subDays(12));
        // Late: paid after due date.
        $this->createInvoice($team, 'paid', now()->subDays(10), now()->subDays(2));
        // Uncollectible: eligible, not on-time.
        $this->createInvoice($team, 'uncollectible', now()->subDays(10));
        // Overdue open: eligible, not on-time.
        $this->createInvoice($team, 'open', now()->subDays(3));

        $result = (new PaymentReliabilityCalculator())->calculate();

        $this->assertSame(4, $result['eligible_invoice_count']);
        $this->assertSame(1, $result['on_time_count']);
        $this->assertSame(25, $result['on_time_rate_pct']);
    }

    public function test_insufficient_data_reports_unavailable(): void
    {
        $this->actingAsTeamMember();
        // No invoices at all.

        $result = (new PaymentReliabilityCalculator())->calculate();

        $this->assertFalse($result['available']);
        $this->assertSame('insufficient_data', $result['reason']);
    }

    public function test_what_if_reflects_real_overdue_invoices(): void
    {
        $team = $this->actingAsTeamMember();

        $this->createInvoice($team, 'paid', now()->subDays(10), now()->subDays(12)); // on-time
        $this->createInvoice($team, 'open', now()->subDays(3)); // overdue #1
        $this->createInvoice($team, 'open', now()->subDays(5)); // overdue #2

        $result = (new PaymentReliabilityCalculator())->calculate();

        $this->assertNotNull($result['what_if']);
        $this->assertSame(2, $result['what_if']['overdue_count']);
    }

    public function test_what_if_is_null_when_there_are_no_overdue_invoices(): void
    {
        $team = $this->actingAsTeamMember();
        $this->createInvoice($team, 'paid', now()->subDays(10), now()->subDays(12));

        $result = (new PaymentReliabilityCalculator())->calculate();

        $this->assertNull($result['what_if']);
    }

    public function test_basis_is_present_when_available(): void
    {
        $team = $this->actingAsTeamMember();
        $this->createInvoice($team, 'paid', now()->subDays(10), now()->subDays(12));

        $result = (new PaymentReliabilityCalculator())->calculate();

        $this->assertNotEmpty($result['basis']);
    }

    public function test_a_second_teams_invoices_never_affect_the_first_teams_rate(): void
    {
        $team = $this->actingAsTeamMember();
        $this->createInvoice($team, 'paid', now()->subDays(10), now()->subDays(12)); // on-time

        $otherTeam = Team::factory()->create();
        // A different team's overdue, unpaid invoice -- must not leak into this calculation.
        $this->createInvoice($otherTeam, 'open', now()->subDays(3));

        $result = (new PaymentReliabilityCalculator())->calculate();

        $this->assertSame(1, $result['eligible_invoice_count']);
        $this->assertSame(100, $result['on_time_rate_pct']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PaymentReliabilityCalculatorTest`
Expected: FAIL — `PaymentReliabilityCalculator` class does not exist yet.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Models\BillingInvoice;

class PaymentReliabilityCalculator
{
    private const TRAILING_MONTHS = 6;

    public function calculate(): array
    {
        $windowStart = now()->subMonths(self::TRAILING_MONTHS);

        $eligible = BillingInvoice::where('due_date', '>=', $windowStart)
            ->where(function ($query) {
                $query->where('status', 'paid')
                    ->orWhere('status', 'uncollectible')
                    ->orWhere(function ($q) {
                        $q->where('status', 'open')->where('due_date', '<', now());
                    });
            })
            ->get();

        if ($eligible->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'insufficient_data',
                'eligible_invoice_count' => null,
                'on_time_count' => null,
                'on_time_rate_pct' => null,
                'basis' => null,
                'what_if' => null,
            ];
        }

        $onTimeCount = $eligible->filter(function (BillingInvoice $invoice) {
            return $invoice->status === 'paid'
                && $invoice->paid_at !== null
                && $invoice->paid_at->lte($invoice->due_date);
        })->count();

        $eligibleCount = $eligible->count();
        $onTimeRatePct = (int) round(($onTimeCount / $eligibleCount) * 100);

        $basis = sprintf(
            'On-time payment rate across %d invoice(s) that came due in the trailing %d months, as of %s.',
            $eligibleCount,
            self::TRAILING_MONTHS,
            now()->toDateString()
        );

        $overdue = BillingInvoice::where('status', 'open')
            ->where('due_date', '<', now())
            ->count();

        $whatIf = null;
        if ($overdue > 0) {
            $projectedRate = (int) round((($onTimeCount + $overdue) / ($eligibleCount + 0)) * 100);
            // Overdue invoices are already counted in $eligibleCount (they
            // match the "open AND due_date < now()" eligibility branch
            // above), so only the numerator changes if they were resolved
            // -- resolving them doesn't add new eligible invoices, it
            // reclassifies existing ones.
            $whatIf = [
                'overdue_count' => $overdue,
                'projected_resolved_rate_pct' => min(100, $projectedRate),
            ];
        }

        return [
            'available' => true,
            'reason' => null,
            'eligible_invoice_count' => $eligibleCount,
            'on_time_count' => $onTimeCount,
            'on_time_rate_pct' => $onTimeRatePct,
            'basis' => $basis,
            'what_if' => $whatIf,
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PaymentReliabilityCalculatorTest`
Expected: PASS (all 7 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/PaymentReliabilityCalculator.php tests/Unit/PaymentReliabilityCalculatorTest.php
git commit -m "feat(cushion): add PaymentReliabilityCalculator for the payment_reliability cushion dimension"
```

---

### Task 2: `PaymentReliability` Livewire component + dashboard wiring

**Files:**
- Create: `app/Livewire/Billing/PaymentReliability.php`
- Create: `resources/views/livewire/billing/payment-reliability.blade.php`
- Modify: `resources/views/dashboard.blade.php`
- Test: `tests/Feature/PaymentReliabilityComponentTest.php`

**Interfaces:**
- Consumes: `PaymentReliabilityCalculator::calculate()` (Task 1).
- Produces: `<livewire:billing.payment-reliability />`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use App\Livewire\Billing\PaymentReliability;
use Tests\TestCase;

class PaymentReliabilityComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_the_cushion_content_when_data_is_available(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        BillingInvoice::create([
            'team_id' => $team->id,
            'invoice_number' => 'INV-1',
            'status' => 'paid',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
            'currency' => 'USD',
            'due_date' => now()->subDays(10),
            'paid_at' => now()->subDays(12),
        ]);

        Livewire::test(PaymentReliability::class)
            ->assertSee('Payment Reliability')
            ->assertSee('100%');
    }

    public function test_renders_without_the_cushion_content_when_no_data(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        Livewire::test(PaymentReliability::class)
            ->assertDontSee('%');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PaymentReliabilityComponentTest`
Expected: FAIL — component does not exist yet.

- [ ] **Step 3: Write the Livewire component**

```php
<?php

namespace App\Livewire\Billing;

use App\Services\PaymentReliabilityCalculator;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PaymentReliability extends Component
{
    #[Computed]
    public function cushion(): array
    {
        return (new PaymentReliabilityCalculator())->calculate();
    }

    public function render()
    {
        return view('livewire.billing.payment-reliability');
    }
}
```

- [ ] **Step 4: Write the Blade view**

```blade
<div class="dot-card" style="padding:1.5rem;">
    <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 1.25rem;">Payment Reliability</h3>
    <div wire:loading.delay class="dot-loading-overlay">
        <span class="material-symbols-rounded dot-spin" style="font-size:22px;color:#818cf8;">progress_activity</span>
    </div>
    <div wire:loading.remove.delay>
    @if($this->cushion['available'])
        <div class="metric-val" style="font-family:'Syne',sans-serif;font-size:1.65rem;font-weight:700;color:#22c55e;">
            {{ $this->cushion['on_time_rate_pct'] }}%
        </div>
        <div style="font-size:11px;color:#71717a;margin-top:6px;">
            {{ $this->cushion['basis'] }}
        </div>
        @if($this->cushion['what_if'])
            <div style="font-size:11px;color:#a1a1aa;margin-top:8px;">
                {{ $this->cushion['what_if']['overdue_count'] }} invoice(s) currently overdue. Resolving them would bring the rate to approximately {{ $this->cushion['what_if']['projected_resolved_rate_pct'] }}%.
            </div>
        @endif
    @else
        <div style="text-align:center;padding:1rem 0;">
            <span class="material-symbols-rounded" style="font-size:32px;color:#3f3f46;display:block;margin-bottom:0.5rem;">receipt_long</span>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">Not enough invoice history yet to compute this.</p>
        </div>
    @endif
    </div>
</div>
```

- [ ] **Step 5: Wire into the dashboard**

In `resources/views/dashboard.blade.php`, change:

```blade
    <livewire:billing.billing-overview />
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-top:1.25rem;">
        <livewire:billing.invoice-table />
        <livewire:billing.usage-dashboard />
    </div>
```

to:

```blade
    <livewire:billing.billing-overview />
    <div style="margin-top:1.25rem;">
        <livewire:billing.payment-reliability />
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-top:1.25rem;">
        <livewire:billing.invoice-table />
        <livewire:billing.usage-dashboard />
    </div>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=PaymentReliabilityComponentTest`
Expected: PASS (both tests)

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS, 0 new failures.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Billing/PaymentReliability.php resources/views/livewire/billing/payment-reliability.blade.php resources/views/dashboard.blade.php tests/Feature/PaymentReliabilityComponentTest.php
git commit -m "feat(cushion): show the payment-reliability cushion on the billing dashboard"
```

- [ ] **Step 9: Manual verification (tinker, matching Dot.Finance's pattern)**

1. Create a team with a mix of on-time, late, overdue, and uncollectible invoices via tinker (single-line `--execute`, avoiding multi-line heredoc quoting issues observed during Dot.Finance's own verification).
2. Call `(new App\Services\PaymentReliabilityCalculator())->calculate()` scoped to that team (via `Auth::login($user)` in the same tinker call, since `HasTeamScope` depends on the authenticated user's current team) and confirm the numbers match hand-calculation.
3. Confirm the zero-invoice case returns `available: false`.
4. Clean up any test data created.
