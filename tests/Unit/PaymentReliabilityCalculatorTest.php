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
            'invoice_number' => 'INV-'.uniqid(),
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

        $result = (new PaymentReliabilityCalculator)->calculate();

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

        $result = (new PaymentReliabilityCalculator)->calculate();

        $this->assertSame(4, $result['eligible_invoice_count']);
        $this->assertSame(1, $result['on_time_count']);
        $this->assertSame(25, $result['on_time_rate_pct']);
    }

    public function test_insufficient_data_reports_unavailable(): void
    {
        $this->actingAsTeamMember();
        // No invoices at all.

        $result = (new PaymentReliabilityCalculator)->calculate();

        $this->assertFalse($result['available']);
        $this->assertSame('insufficient_data', $result['reason']);
    }

    public function test_what_if_reflects_real_overdue_invoices(): void
    {
        $team = $this->actingAsTeamMember();

        $this->createInvoice($team, 'paid', now()->subDays(10), now()->subDays(12)); // on-time
        $this->createInvoice($team, 'open', now()->subDays(3)); // overdue #1
        $this->createInvoice($team, 'open', now()->subDays(5)); // overdue #2

        $result = (new PaymentReliabilityCalculator)->calculate();

        $this->assertNotNull($result['what_if']);
        $this->assertSame(2, $result['what_if']['overdue_count']);
    }

    public function test_what_if_is_null_when_there_are_no_overdue_invoices(): void
    {
        $team = $this->actingAsTeamMember();
        $this->createInvoice($team, 'paid', now()->subDays(10), now()->subDays(12));

        $result = (new PaymentReliabilityCalculator)->calculate();

        $this->assertNull($result['what_if']);
    }

    public function test_basis_is_present_when_available(): void
    {
        $team = $this->actingAsTeamMember();
        $this->createInvoice($team, 'paid', now()->subDays(10), now()->subDays(12));

        $result = (new PaymentReliabilityCalculator)->calculate();

        $this->assertNotEmpty($result['basis']);
    }

    public function test_a_second_teams_invoices_never_affect_the_first_teams_rate(): void
    {
        $team = $this->actingAsTeamMember();
        $this->createInvoice($team, 'paid', now()->subDays(10), now()->subDays(12)); // on-time

        $otherTeam = Team::factory()->create();
        // A different team's overdue, unpaid invoice -- must not leak into this calculation.
        $this->createInvoice($otherTeam, 'open', now()->subDays(3));

        $result = (new PaymentReliabilityCalculator)->calculate();

        $this->assertSame(1, $result['eligible_invoice_count']);
        $this->assertSame(100, $result['on_time_rate_pct']);
    }
}
