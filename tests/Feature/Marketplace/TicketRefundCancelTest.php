<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\SendMarketplaceCallback;
use App\Models\BillingAuditEvent;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

class TicketRefundCancelTest extends TestCase
{
    use BillingFixtures;
    use MarketplaceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configureMarketplace();
        $this->fakeMarketplaceHttp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function audits(): array
    {
        return BillingAuditEvent::withoutGlobalScope('team')->orderBy('id')->pluck('action')->all();
    }

    // ---- refund ------------------------------------------------------------------------------

    public function test_refunding_a_paid_ticket_asks_paystack_but_the_ticket_stays_paid_until_the_webhook(): void
    {
        $ticket = $this->makePaidTicket();

        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/refund")->assertOk()
            ->assertJsonPath('status', 'paid')->assertJsonPath('refund_requested', true)->assertJsonPath('refunded_at', null);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.paystack.co/refund'
            && $r->data() === ['transaction' => $ticket->paystack_reference]);

        $ticket->refresh();
        $this->assertSame('paid', $ticket->status);
        $this->assertNotNull($ticket->refund_requested_at);
        $this->assertSame(['ticket.refund_requested'], $this->audits());
        Queue::assertNothingPushed();
    }

    public function test_asking_again_changes_nothing_and_calls_paystack_no_more(): void
    {
        $ticket = $this->makePaidTicket();

        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/refund")->assertOk();
        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/refund")->assertOk()->assertJsonPath('refund_requested', true);

        Http::assertSentCount(1);
        $this->assertSame(['ticket.refund_requested'], $this->audits());
    }

    public function test_the_refund_processed_webhook_marks_the_ticket_refunded_and_queues_the_callback(): void
    {
        $ticket = $this->makePaidTicket();
        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/refund")->assertOk();

        $this->webhook($this->ticketRefundProcessed($ticket))->assertOk()->assertJson(['status' => 'applied']);

        $ticket->refresh();
        $this->assertSame(['refunded', '2026-11-05T09:00:00+00:00'], [$ticket->status, $ticket->refunded_at->toIso8601String()]);
        $this->assertSame(['ticket.refund_requested', 'ticket.refunded'], $this->audits());
        $this->assertSame(0, PaymentEvent::withoutGlobalScope('team')->count());
        Queue::assertPushed(SendMarketplaceCallback::class, 1);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($job) => $job->payload === [
            'event' => 'ticket.refunded', 'ticket_ref' => $ticket->ticket_ref, 'reference' => $ticket->paystack_reference,
            'amount_cents' => 20000, 'currency' => 'ZAR', 'refunded_at' => '2026-11-05T09:00:00+00:00',
            'refund_amount_cents' => 20000, 'refunded_total_cents' => 20000, 'refund_key' => 'full',
        ]);

        $this->signed('GET', "/api/seminar-tickets/{$ticket->ticket_ref}")->assertJsonPath('status', 'refunded')->assertJsonPath('refunded_at', '2026-11-05T09:00:00+00:00');
    }

    public function test_a_replayed_refund_webhook_is_a_no_op(): void
    {
        $ticket = $this->makePaidTicket();
        $this->webhook($this->ticketRefundProcessed($ticket))->assertOk()->assertJson(['status' => 'applied']);
        $refundedAt = $ticket->fresh()->refunded_at;

        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->webhook($this->ticketRefundProcessed($ticket))->assertOk()->assertJson(['status' => 'replayed']);

        $this->assertTrue($ticket->fresh()->refunded_at->equalTo($refundedAt));
        Queue::assertPushed(SendMarketplaceCallback::class, 1);
        $this->assertSame(['ticket.refunded'], $this->audits());
    }

    public function test_a_refund_started_in_the_paystack_dashboard_also_marks_the_ticket_refunded(): void
    {
        $ticket = $this->makePaidTicket();

        $this->webhook($this->ticketRefundProcessed($ticket))->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame('refunded', $ticket->fresh()->status);
    }

    public function test_refunding_an_already_refunded_ticket_is_an_idempotent_success(): void
    {
        $ticket = $this->makePaidTicket(['status' => 'refunded', 'refunded_cents' => 20000, 'refunded_at' => now()]);

        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/refund")->assertOk()->assertJsonPath('status', 'refunded');
        Http::assertNothingSent();
    }

    public function test_a_refund_above_what_is_still_refundable_is_held_and_not_applied(): void
    {
        $ticket = $this->makePaidTicket();

        $this->webhook($this->ticketRefundProcessed($ticket, ['amount' => 20001]))->assertOk()->assertJson(['status' => 'held']);

        $ticket->refresh();
        $this->assertSame(['paid', 0], [$ticket->status, $ticket->refunded_cents]);
        $event = PaymentEvent::withoutGlobalScope('team')->sole();
        $this->assertSame(['needs_review', 'refund_exceeds_remaining', 'ticket_refund', null], [$event->status, $event->reason, $event->type, $event->team_id]);
        $this->assertSame(['ticket.held'], $this->audits());
        Queue::assertNothingPushed();
    }

    public function test_a_refund_in_another_currency_is_held(): void
    {
        $ticket = $this->makePaidTicket();

        $this->webhook($this->ticketRefundProcessed($ticket, ['currency' => 'NGN']))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame('paid', $ticket->fresh()->status);
        $this->assertSame('refund_currency_mismatch', PaymentEvent::withoutGlobalScope('team')->sole()->reason);
    }

    public function test_a_refund_whose_own_status_is_not_processed_is_held(): void
    {
        $ticket = $this->makePaidTicket();

        $this->webhook($this->ticketRefundProcessed($ticket, ['status' => 'failed']))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame('paid', $ticket->fresh()->status);
        $this->assertSame('refund_not_processed', PaymentEvent::withoutGlobalScope('team')->sole()->reason);
    }

    public function test_a_refund_for_an_unpaid_ticket_is_held(): void
    {
        $ticket = $this->makeTicket();

        $this->webhook($this->ticketRefundProcessed($ticket))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertSame('refund_for_unpaid_ticket', PaymentEvent::withoutGlobalScope('team')->sole()->reason);
    }

    public function test_a_pending_or_cancelled_ticket_cannot_be_refunded(): void
    {
        $pending = $this->makeTicket();
        $failed = $this->makeTicket(['status' => 'failed', 'failure_reason' => 'cancelled']);

        $this->signed('POST', "/api/seminar-tickets/{$pending->ticket_ref}/refund")->assertStatus(409)->assertJsonPath('error', 'ticket_not_refundable');
        $this->signed('POST', "/api/seminar-tickets/{$failed->ticket_ref}/refund")->assertStatus(409)->assertJsonPath('error', 'ticket_not_refundable');

        Http::assertNothingSent();
        $this->assertNull($pending->fresh()->refund_requested_at);
    }

    public function test_an_unknown_ticket_is_a_404(): void
    {
        $this->signed('POST', '/api/seminar-tickets/nope/refund')->assertNotFound();
        $this->signed('POST', '/api/seminar-tickets/nope/cancel')->assertNotFound();
    }

    public function test_a_paystack_refusal_is_a_422_and_leaves_the_ticket_unchanged(): void
    {
        $ticket = $this->makePaidTicket();
        $this->fakeMarketplaceHttp(['api.paystack.co/refund' => Http::response(['status' => false, 'message' => 'Transaction has already been fully reversed'], 400)]);

        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/refund")->assertUnprocessable()->assertJsonPath('error', 'refund_rejected');

        $this->assertNull($ticket->fresh()->refund_requested_at);
        $this->assertSame([], $this->audits());
    }

    public function test_paystack_being_down_is_a_503_and_the_refund_can_be_asked_for_again(): void
    {
        $ticket = $this->makePaidTicket();
        $this->fakeMarketplaceHttp(['api.paystack.co/refund' => Http::response('', 500)]);

        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/refund")->assertStatus(503);
        $this->assertNull($ticket->fresh()->refund_requested_at);

        $this->fakeMarketplaceHttp();
        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/refund")->assertOk();
        $this->assertNotNull($ticket->fresh()->refund_requested_at);
    }

    public function test_a_refund_processed_event_for_an_invoice_payment_is_still_only_recorded(): void
    {
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);
        $attempt = $this->makeAttempt($invoice, [], $owner);
        $this->makePaidTicket();

        $this->webhook(['event' => 'refund.processed', 'data' => [
            'status' => 'processed', 'transaction_reference' => $attempt->reference, 'refund_reference' => 'rf_inv_1', 'amount' => 100000, 'currency' => 'ZAR',
        ]])->assertOk()->assertJson(['status' => 'held']);

        $event = PaymentEvent::withoutGlobalScope('team')->sole();
        $this->assertSame(['refund', 'needs_review', 'refund_not_modelled', $invoice->team_id], [$event->type, $event->status, $event->reason, $event->team_id]);
        Queue::assertNothingPushed();
    }

    // ---- cancel ------------------------------------------------------------------------------

    public function test_cancelling_a_pending_ticket_marks_it_failed_so_it_can_never_be_paid(): void
    {
        $ticket = $this->makeTicket();

        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/cancel")->assertOk()->assertJsonPath('status', 'failed');

        $ticket->refresh();
        $this->assertSame(['failed', 'cancelled'], [$ticket->status, $ticket->failure_reason]);
        $this->assertSame(['ticket.cancelled'], $this->audits());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_cancelling_twice_is_fine_and_audited_once(): void
    {
        $ticket = $this->makeTicket();

        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/cancel")->assertOk();
        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/cancel")->assertOk()->assertJsonPath('status', 'failed');

        $this->assertSame(['ticket.cancelled'], $this->audits());
    }

    public function test_a_paid_or_refunded_ticket_cannot_be_cancelled(): void
    {
        $paid = $this->makePaidTicket();
        $refunded = $this->makePaidTicket(['status' => 'refunded', 'refunded_cents' => 20000, 'refunded_at' => now()]);

        $this->signed('POST', "/api/seminar-tickets/{$paid->ticket_ref}/cancel")->assertStatus(409)->assertJsonPath('error', 'ticket_not_cancellable');
        $this->signed('POST', "/api/seminar-tickets/{$refunded->ticket_ref}/cancel")->assertStatus(409);

        $this->assertSame(['paid', 'refunded'], [$paid->fresh()->status, $refunded->fresh()->status]);
    }

    public function test_a_payment_that_arrives_after_cancel_is_held_and_the_ticket_stays_failed(): void
    {
        $ticket = $this->makeTicket();
        $this->signed('POST', "/api/seminar-tickets/{$ticket->ticket_ref}/cancel")->assertOk();
        $this->fakeTicketVerify($ticket);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame('failed', $ticket->fresh()->status);
        $this->assertSame('ticket_cancelled', PaymentEvent::withoutGlobalScope('team')->sole()->reason);
        $this->assertSame(['ticket.cancelled', 'ticket.held'], $this->audits());
        Queue::assertNothingPushed();
    }
}
