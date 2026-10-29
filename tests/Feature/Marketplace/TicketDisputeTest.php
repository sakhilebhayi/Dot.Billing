<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\SendMarketplaceCallback;
use App\Models\BillingAuditEvent;
use App\Models\PaymentEvent;
use App\Models\TicketDispute;
use App\Models\TicketPayment;
use App\Services\Marketplace\TicketSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

class TicketDisputeTest extends TestCase
{
    use BillingFixtures;
    use MarketplaceFixtures;
    use RefreshDatabase;

    private TicketPayment $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configureMarketplace();
        $this->fakeMarketplaceHttp();
        Queue::fake();
        $this->ticket = $this->makePaidTicket();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function open(?TicketPayment $ticket = null, array $data = []): void
    {
        $this->webhook($this->disputeEvent('charge.dispute.create', ($ticket ?? $this->ticket)->paystack_reference, $data))->assertOk();
    }

    private function resolve(string $resolution, ?TicketPayment $ticket = null, array $data = [])
    {
        return $this->webhook($this->disputeEvent('charge.dispute.resolve', ($ticket ?? $this->ticket)->paystack_reference, ['resolution' => $resolution] + $data));
    }

    private function audits(): array
    {
        return BillingAuditEvent::withoutGlobalScope('team')->orderBy('id')->pluck('action')->all();
    }

    // ---- opening ---------------------------------------------------------------------------------

    public function test_an_opened_dispute_is_recorded_and_the_ticket_becomes_disputed_with_a_callback(): void
    {
        $this->webhook($this->disputeEvent('charge.dispute.create', $this->ticket->paystack_reference))->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame('disputed', $this->ticket->fresh()->status);
        $dispute = TicketDispute::sole();
        $this->assertSame([$this->ticket->id, '358950', 'open', null, '2026-11-06T08:00:00+00:00', null], [
            $dispute->ticket_payment_id, $dispute->paystack_dispute_id, $dispute->status, $dispute->resolution, $dispute->opened_at->toIso8601String(), $dispute->resolved_at,
        ]);
        $this->assertSame(['ticket.disputed'], $this->audits());
        Http::assertNothingSent();

        Queue::assertPushed(SendMarketplaceCallback::class, 1);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload === [
            'event' => 'ticket.disputed', 'ticket_ref' => $this->ticket->ticket_ref, 'reference' => $this->ticket->paystack_reference,
            'amount_cents' => 20000, 'currency' => 'ZAR', 'opened_at' => '2026-11-06T08:00:00+00:00',
        ]);

        $this->signed('GET', "/api/seminar-tickets/{$this->ticket->ticket_ref}")->assertOk()
            ->assertJsonPath('status', 'disputed')->assertJsonPath('dispute_status', 'open')->assertJsonPath('refunded_cents', 0);
    }

    public function test_a_replayed_dispute_event_changes_nothing(): void
    {
        $this->open();
        $this->webhook($this->disputeEvent('charge.dispute.create', $this->ticket->paystack_reference))->assertOk()->assertJson(['status' => 'replayed']);

        $this->assertSame(1, TicketDispute::count());
        Queue::assertPushed(SendMarketplaceCallback::class, 1);
        $this->assertSame(['ticket.disputed'], $this->audits());
    }

    public function test_the_host_cannot_refund_while_a_dispute_is_open(): void
    {
        $this->open();

        $this->signed('POST', "/api/seminar-tickets/{$this->ticket->ticket_ref}/refund")->assertStatus(409)->assertJsonPath('error', 'ticket_disputed');
        $this->signed('POST', "/api/seminar-tickets/{$this->ticket->ticket_ref}/refund", ['amount_cents' => 100, 'refund_key' => 'x'])->assertStatus(409)->assertJsonPath('error', 'ticket_disputed');

        Http::assertNothingSent();
        $this->assertSame(0, $this->ticket->refunds()->count());
    }

    public function test_a_partially_refunded_ticket_can_be_disputed_and_returns_to_partially_refunded_when_won(): void
    {
        $this->ticket->forceFill(['status' => 'partially_refunded', 'refunded_cents' => 5000])->save();

        $this->open();
        $this->assertSame('disputed', $this->ticket->fresh()->status);

        $this->resolve('declined')->assertOk();
        $this->assertSame('partially_refunded', $this->ticket->fresh()->status);
    }

    public function test_a_dispute_on_a_ticket_that_was_never_paid_is_held_for_an_operator(): void
    {
        $pending = $this->makeTicket();

        $this->webhook($this->disputeEvent('charge.dispute.create', $pending->paystack_reference))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame(['pending', 0], [$pending->fresh()->status, TicketDispute::count()]);
        $event = PaymentEvent::withoutGlobalScope('team')->sole();
        $this->assertSame(['needs_review', 'dispute_for_unpaid_ticket', 'ticket_dispute'], [$event->status, $event->reason, $event->type]);
        Queue::assertNothingPushed();
    }

    public function test_a_dispute_on_a_refunded_ticket_is_recorded_without_changing_its_status(): void
    {
        $refunded = $this->makePaidTicket(['status' => 'refunded', 'refunded_cents' => 20000, 'refunded_at' => now()]);

        $this->open($refunded);

        $this->assertSame('refunded', $refunded->fresh()->status);
        $this->assertSame('open', $refunded->fresh()->disputeStatus());
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload['event'] === 'ticket.disputed');
    }

    public function test_a_dispute_without_an_id_is_held_as_unreadable(): void
    {
        $this->webhook($this->disputeEvent('charge.dispute.create', $this->ticket->paystack_reference, ['id' => null]))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame('paid', $this->ticket->fresh()->status);
        $this->assertSame('unreadable_payload', PaymentEvent::withoutGlobalScope('team')->sole()->reason);
    }

    // ---- resolving -------------------------------------------------------------------------------

    public function test_a_won_dispute_returns_the_ticket_to_paid_and_queues_the_callback(): void
    {
        $this->open();

        $this->resolve('declined')->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame('paid', $this->ticket->fresh()->status);
        $dispute = TicketDispute::sole();
        $this->assertSame(['resolved', 'won', 'declined', '2026-11-20T10:00:00+00:00'], [$dispute->status, $dispute->resolution, $dispute->paystack_resolution, $dispute->resolved_at->toIso8601String()]);
        $this->assertSame(['ticket.disputed', 'ticket.dispute_resolved'], $this->audits());
        $this->assertSame(0, PaymentEvent::withoutGlobalScope('team')->count());

        Queue::assertPushed(SendMarketplaceCallback::class, 2);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload === [
            'event' => 'ticket.dispute_resolved', 'ticket_ref' => $this->ticket->ticket_ref, 'reference' => $this->ticket->paystack_reference,
            'amount_cents' => 20000, 'currency' => 'ZAR', 'outcome' => 'won', 'resolved_at' => '2026-11-20T10:00:00+00:00',
        ]);

        $this->signed('GET', "/api/seminar-tickets/{$this->ticket->ticket_ref}")->assertJsonPath('status', 'paid')->assertJsonPath('dispute_status', 'resolved');
        // And the host may refund again.
        $this->signed('POST', "/api/seminar-tickets/{$this->ticket->ticket_ref}/refund")->assertOk();
    }

    public function test_a_lost_dispute_sets_chargeback_and_the_host_still_cannot_refund(): void
    {
        $this->open();

        $this->resolve('merchant-accepted')->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame('chargeback', $this->ticket->fresh()->status);
        $this->assertSame('lost', TicketDispute::sole()->resolution);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload['event'] === 'ticket.dispute_resolved' && $j->payload['outcome'] === 'lost');
        $this->assertSame(0, PaymentEvent::withoutGlobalScope('team')->count());

        $this->signed('POST', "/api/seminar-tickets/{$this->ticket->ticket_ref}/refund")->assertStatus(409)->assertJsonPath('error', 'ticket_not_refundable');
        $this->signed('GET', "/api/seminar-tickets/{$this->ticket->ticket_ref}")->assertJsonPath('status', 'chargeback')->assertJsonPath('dispute_status', 'resolved');
    }

    public function test_an_unrecognised_outcome_is_recorded_as_other_kept_disputed_and_held_for_an_operator(): void
    {
        $this->open();

        $this->resolve('something-new')->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame('disputed', $this->ticket->fresh()->status);
        $this->assertSame(['resolved', 'other', 'something-new'], [TicketDispute::sole()->status, TicketDispute::sole()->resolution, TicketDispute::sole()->paystack_resolution]);
        $event = PaymentEvent::withoutGlobalScope('team')->sole();
        $this->assertSame(['needs_review', 'dispute_outcome_unknown'], [$event->status, $event->reason]);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload['event'] === 'ticket.dispute_resolved' && $j->payload['outcome'] === 'other');
        $this->signed('POST', "/api/seminar-tickets/{$this->ticket->ticket_ref}/refund")->assertStatus(409)->assertJsonPath('error', 'ticket_disputed');
    }

    public function test_a_replayed_resolve_changes_nothing(): void
    {
        $this->open();
        $this->resolve('merchant-accepted')->assertOk();

        $this->resolve('merchant-accepted')->assertOk()->assertJson(['status' => 'replayed']);
        // A later contradictory resolve for the same dispute is also ignored.
        $this->resolve('declined')->assertOk()->assertJson(['status' => 'replayed']);

        $this->assertSame('chargeback', $this->ticket->fresh()->status);
        Queue::assertPushed(SendMarketplaceCallback::class, 2);
    }

    public function test_a_resolve_for_a_dispute_never_seen_opened_is_recorded_resolved(): void
    {
        $this->resolve('declined')->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame('paid', $this->ticket->fresh()->status);
        $this->assertSame(['resolved', 'won'], [TicketDispute::sole()->status, TicketDispute::sole()->resolution]);
        Queue::assertPushed(SendMarketplaceCallback::class, 1);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload['event'] === 'ticket.dispute_resolved');
    }

    public function test_a_refund_that_was_already_in_flight_is_still_counted_during_a_dispute_without_changing_the_status(): void
    {
        $this->open();

        $this->webhook($this->ticketRefundProcessed($this->ticket, ['amount' => 5000, 'id' => 8123]))->assertOk()->assertJson(['status' => 'applied']);

        $ticket = $this->ticket->fresh();
        $this->assertSame(['disputed', 5000], [$ticket->status, $ticket->refunded_cents]);

        $this->resolve('declined')->assertOk();
        $this->assertSame('partially_refunded', $this->ticket->fresh()->status);
    }

    public function test_a_charge_success_replay_for_a_disputed_ticket_is_a_no_op(): void
    {
        $this->open();
        $this->fakeTicketVerify($this->ticket);

        $this->webhook($this->ticketChargeSuccess($this->ticket))->assertOk()->assertJson(['status' => 'replayed']);

        $this->assertSame('disputed', $this->ticket->fresh()->status);
        $this->signed('POST', '/api/seminar-tickets', [
            'ticket_ref' => $this->ticket->ticket_ref, 'payout_account_id' => $this->ticket->payout_account_id, 'buyer_email' => 'attendee@example.test',
            'amount_cents' => 20000, 'currency' => 'ZAR', 'description' => 'Seminar ticket',
        ])->assertStatus(409)->assertJsonPath('error', 'already_paid');
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function resolutions(): array
    {
        return [
            'declined' => ['declined', 'won'], 'won' => ['won', 'won'], 'upper case' => ['DECLINED ', 'won'],
            'merchant-accepted' => ['merchant-accepted', 'lost'], 'lost' => ['lost', 'lost'],
            'accepted' => ['accepted', 'other'], 'empty' => ['', 'other'], 'null' => [null, 'other'],
        ];
    }

    #[DataProvider('resolutions')]
    public function test_paystacks_resolution_text_is_normalised_to_won_lost_or_other(?string $text, string $expected): void
    {
        $this->assertSame($expected, TicketSettlement::normaliseResolution($text));
    }

    // ---- everything that is not a ticket ---------------------------------------------------------

    public function test_dispute_events_for_non_ticket_references_are_ignored_exactly_as_before(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);
        $attempt = $this->makeAttempt($invoice, [], $owner);

        foreach (['charge.dispute.create', 'charge.dispute.resolve'] as $event) {
            $this->webhook($this->disputeEvent($event, $attempt->reference, ['resolution' => 'merchant-accepted']))->assertOk()->assertExactJson(['status' => 'ignored']);
            $this->webhook($this->disputeEvent($event, 'dbt-nobody-knows'))->assertOk()->assertExactJson(['status' => 'ignored']);
            $this->webhook(['event' => $event, 'data' => ['id' => 5]])->assertOk()->assertExactJson(['status' => 'ignored']);
        }

        $this->assertSame(0, PaymentEvent::withoutGlobalScope('team')->count());
        $this->assertSame(0, TicketDispute::count());
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame('paid', $this->ticket->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_while_the_marketplace_is_off_a_ticket_dispute_is_ignored_like_any_other(): void
    {
        config(['billing.marketplace.enabled' => false]);

        $this->webhook($this->disputeEvent('charge.dispute.create', $this->ticket->paystack_reference))->assertOk()->assertExactJson(['status' => 'ignored']);

        $this->assertSame(['paid', 0], [$this->ticket->fresh()->status, TicketDispute::count()]);
    }

    public function test_dispute_events_need_a_valid_paystack_signature(): void
    {
        $this->webhook($this->disputeEvent('charge.dispute.create', $this->ticket->paystack_reference), 'bad')->assertUnauthorized();

        $this->assertSame(0, TicketDispute::count());
    }

    public function test_billing_never_calls_paystack_about_a_dispute(): void
    {
        $this->open();
        $this->resolve('declined')->assertOk();
        $this->open(null, ['id' => 2]);
        $this->resolve('merchant-accepted', null, ['id' => 2])->assertOk();

        Http::assertNothingSent();
    }
}
