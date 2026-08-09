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
