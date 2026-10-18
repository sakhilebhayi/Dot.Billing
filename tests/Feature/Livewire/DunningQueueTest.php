<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Billing\DunningQueue;
use App\Models\BillingAuditEvent;
use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Models\DunningCase;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class DunningQueueTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function operator(): User
    {
        return $this->makeOperator();
    }

    /** @return array{team: Team, subscription: BillingSubscription, invoice: BillingInvoice, case: DunningCase} */
    private function makeCase(): array
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $subscription = $this->makeSubscription($team, $this->makePlan(), ['status' => 'past_due']);
        $invoice = $this->makeInvoice($team, [
            'subscription_id' => $subscription->id,
            'due_date' => now()->subDays(5),
            'total_cents' => 50000,
        ]);
        $case = DunningCase::create([
            'team_id' => $team->id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open',
        ]);

        return compact('team', 'subscription', 'invoice', 'case');
    }

    public function test_extending_grants_a_real_grace_state_and_leaves_the_invoice_honest(): void
    {
        ['invoice' => $invoice, 'case' => $case] = $this->makeCase();
        $originalDue = $invoice->due_date->copy();
        $operator = $this->operator();

        Livewire::actingAs($operator)->test(DunningQueue::class)->call('extend', $case->id, 7);

        $case->refresh();
        $this->assertSame('grace', $case->status);
        $this->assertTrue($case->grace_until->equalTo(now()->addDays(7)));
        $this->assertNull($case->resolved_at, 'a grace period is not a resolution');

        $invoice->refresh();
        $this->assertTrue($invoice->due_date->equalTo($originalDue), 'the real due date is never rewritten');
        $this->assertSame('open', $invoice->status);
        $this->assertTrue($invoice->grace_until->equalTo(now()->addDays(7)));
        $this->assertFalse($invoice->isOverdue(), 'inside grace the invoice is not overdue');

        $audit = BillingAuditEvent::withoutGlobalScope('team')->where('action', 'dunning.grace_granted')->sole();
        $this->assertSame($operator->id, $audit->actor_id);
    }

    public function test_a_case_in_grace_stays_in_the_queue_and_shows_when_it_ends(): void
    {
        ['case' => $case] = $this->makeCase();
        $case->update(['status' => 'grace', 'grace_until' => now()->addDays(4)]);

        Livewire::actingAs($this->operator())->test(DunningQueue::class)
            ->assertSee('In grace until 9 Nov 2026');
    }

    public function test_cancel_ends_the_subscription_tied_to_the_invoice_and_nothing_else(): void
    {
        ['team' => $team, 'subscription' => $subscription, 'case' => $case] = $this->makeCase();
        $operator = $this->operator();

        // An older, already ended subscription of the same team must not be touched or chosen.
        $old = $this->makeSubscription($team, $this->makePlan(), ['status' => 'canceled', 'canceled_at' => now()->subYear(), 'ended_at' => now()->subYear()]);
        $oldEndedAt = $old->fresh()->ended_at;

        Livewire::actingAs($operator)->test(DunningQueue::class)->call('cancelSubscription', $case->id);

        $this->assertSame('canceled', $subscription->fresh()->status);
        $this->assertTrue($old->fresh()->ended_at->equalTo($oldEndedAt));
        $this->assertSame('canceled', $case->fresh()->status);
        $this->assertNotNull($case->fresh()->resolved_at);
        $this->assertSame($operator->id, $case->fresh()->resolved_by);

        $audit = BillingAuditEvent::withoutGlobalScope('team')->where('action', 'subscription.canceled')->sole();
        $this->assertSame($operator->id, $audit->actor_id);
        $this->assertSame($subscription->id, $audit->subject_id);
    }

    public function test_cancel_picks_the_subscription_of_the_invoice_even_when_it_is_not_the_first_one(): void
    {
        ['team' => $team, 'case' => $case] = $this->makeCase();
        // Another team's subscription with a lower id must never be the one that is cancelled.
        $other = $this->makeOwner();
        $otherSub = $this->makeSubscription($other->currentTeam, $this->makePlan());

        Livewire::actingAs($this->operator())->test(DunningQueue::class)->call('cancelSubscription', $case->id);

        $this->assertSame('active', $otherSub->fresh()->status);
    }

    public function test_cancel_refuses_to_guess_when_the_case_has_no_subscription_to_cancel(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $invoice = $this->makeInvoice($team, ['due_date' => now()->subDays(5)]); // legacy-style: no subscription_id
        $case = DunningCase::create(['team_id' => $team->id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open']);

        Livewire::actingAs($this->operator())->test(DunningQueue::class)
            ->call('cancelSubscription', $case->id)
            ->assertSee('no running subscription');

        $this->assertSame('open', $case->fresh()->status, 'the case stays open for a human');
    }

    public function test_cancel_falls_back_to_the_only_live_subscription_for_a_legacy_invoice_with_no_link(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $sub = $this->makeSubscription($team, $this->makePlan(), ['status' => 'past_due']);
        $invoice = $this->makeInvoice($team, ['due_date' => now()->subDays(5)]);
        $case = DunningCase::create(['team_id' => $team->id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open']);

        Livewire::actingAs($this->operator())->test(DunningQueue::class)->call('cancelSubscription', $case->id);

        $this->assertSame('canceled', $sub->fresh()->status);
    }

    public function test_dismiss_changes_nothing_but_the_case_and_is_audited(): void
    {
        ['case' => $case, 'invoice' => $invoice, 'subscription' => $subscription] = $this->makeCase();
        $due = $invoice->due_date->copy();

        Livewire::actingAs($this->operator())->test(DunningQueue::class)->call('dismiss', $case->id);

        $this->assertSame('dismissed', $case->fresh()->status);
        $this->assertTrue($invoice->fresh()->due_date->equalTo($due));
        $this->assertSame('past_due', $subscription->fresh()->status);
        $this->assertSame(1, BillingAuditEvent::withoutGlobalScope('team')->where('action', 'dunning.dismissed')->count());
    }

    public function test_a_non_operator_is_forbidden_on_every_action(): void
    {
        ['case' => $case, 'subscription' => $subscription] = $this->makeCase();
        $regular = User::factory()->create();

        foreach ([['extend', [$case->id, 7]], ['cancelSubscription', [$case->id]], ['dismiss', [$case->id]]] as [$method, $args]) {
            Livewire::actingAs($regular)->test(DunningQueue::class)->call($method, ...$args)->assertForbidden();
        }

        $this->assertSame('open', $case->fresh()->status);
        $this->assertSame('past_due', $subscription->fresh()->status);
    }

    public function test_the_queue_shows_amounts_in_the_invoices_own_currency(): void
    {
        ['invoice' => $invoice] = $this->makeCase();
        $invoice->update(['currency' => 'USD', 'total_cents' => 123450, 'invoice_number' => 'QUEUE-USD-DISTINCT-5521']);

        Livewire::actingAs($this->operator())->test(DunningQueue::class)
            ->assertSee('QUEUE-USD-DISTINCT-5521')
            ->assertSee('USD 1,234.50');
    }

    public function test_it_never_cancels_anything_on_its_own(): void
    {
        ['subscription' => $subscription] = $this->makeCase();

        $this->artisan('billing:scan-alerts')->assertSuccessful();
        $this->artisan('billing:advance-subscriptions')->assertSuccessful();

        $this->assertNotSame('canceled', $subscription->fresh()->status);
    }
}
