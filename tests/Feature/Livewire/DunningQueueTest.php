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
