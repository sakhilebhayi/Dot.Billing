<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\SendMarketplaceCallback;
use App\Models\BillingAuditEvent;
use App\Models\PaymentEvent;
use App\Models\TicketPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

class TicketWebhookTest extends TestCase
{
    use BillingFixtures;
    use MarketplaceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configureMarketplace();
        Queue::fake();
        $this->captureLogs();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function events()
    {
        return PaymentEvent::withoutGlobalScope('team');
    }

    private function audits(): array
    {
        return BillingAuditEvent::withoutGlobalScope('team')->orderBy('id')->pluck('action')->all();
    }

    public function test_a_verified_charge_marks_the_ticket_paid_and_queues_the_callback(): void
    {
        $ticket = $this->makeTicket();
        $this->fakeTicketVerify($ticket);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk()->assertJson(['status' => 'applied']);

        $ticket->refresh();
        $this->assertSame('paid', $ticket->status);
        $this->assertSame('2026-11-05T09:01:00+00:00', $ticket->paid_at->toIso8601String());
        $this->assertSame('5550001', $ticket->paystack_transaction_id);
        $this->assertSame(0, $this->events()->count());

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://api.paystack.co/transaction/verify/'.$ticket->paystack_reference);

        $this->assertSame(['ticket.paid'], $this->audits());
        Queue::assertPushed(SendMarketplaceCallback::class, 1);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($job) => $job->payload === [
            'event' => 'ticket.paid', 'ticket_ref' => $ticket->ticket_ref, 'reference' => $ticket->paystack_reference,
            'amount_cents' => 20000, 'platform_fee_cents' => 2000, 'currency' => 'ZAR', 'paid_at' => '2026-11-05T09:01:00+00:00',
        ]);
    }

    public function test_the_ticket_is_paid_from_what_paystack_verifies_not_from_the_webhook_body(): void
    {
        $ticket = $this->makeTicket();
        // The body claims success; Paystack's own answer says the customer abandoned the payment.
        $this->fakeTicketVerify($ticket, ['status' => 'abandoned']);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame('pending', $ticket->fresh()->status);
        $event = $this->events()->sole();
        $this->assertSame(['unmatched', 'status_not_success', 'ticket_payment'], [$event->status, $event->reason, $event->type]);
        Queue::assertNothingPushed();
    }

    public function test_a_replay_is_a_no_op(): void
    {
        $ticket = $this->makeTicket();
        $this->fakeTicketVerify($ticket);
        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk();
        $paidAt = $ticket->fresh()->paid_at;

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->fakeTicketVerify($ticket, ['paid_at' => '2026-11-05T10:00:00.000Z']);
        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk()->assertJson(['status' => 'replayed']);

        $this->assertTrue($ticket->fresh()->paid_at->equalTo($paidAt));
        $this->assertSame(['ticket.paid'], $this->audits());
        Queue::assertPushed(SendMarketplaceCallback::class, 1);
        $this->assertSame(0, $this->events()->count());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function mismatches(): array
    {
        return [
            'amount too low' => [['amount' => 19999], 'amount_mismatch'],
            'amount too high' => [['amount' => 20001], 'amount_mismatch'],
            'currency' => [['currency' => 'NGN'], 'currency_mismatch'],
            'reference' => [['reference' => 'dbt-someone-elses'], 'reference_mismatch'],
        ];
    }

    #[DataProvider('mismatches')]
    public function test_a_verified_transaction_that_disagrees_with_the_ticket_is_held_for_an_operator(array $verified, string $reason): void
    {
        $ticket = $this->makeTicket();
        $this->fakeTicketVerify($ticket, $verified);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->paid_at);
        $event = $this->events()->sole();
        $this->assertSame(['unmatched', $reason, null, null], [$event->status, $event->reason, $event->team_id, $event->invoice_id]);
        $this->assertSame($ticket->ticket_ref, $event->payload['ticket_ref']);
        $this->assertSame(['ticket.held'], $this->audits());
        Queue::assertNothingPushed();

        // Redelivery of the same held event adds no second row and no second audit entry.
        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk();
        $this->assertSame(1, $this->events()->count());
        $this->assertSame(['ticket.held'], $this->audits());
    }

    public function test_when_paystack_cannot_be_reached_to_verify_the_webhook_answers_503_so_it_is_redelivered(): void
    {
        $ticket = $this->makeTicket();
        $this->fakeTicketVerify($ticket, [], 502);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertStatus(503);
        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertSame(0, $this->events()->count());

        $this->fakeTicketVerify($ticket);
        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk();
        $this->assertSame('paid', $ticket->fresh()->status);
    }

    public function test_an_unreadable_verify_answer_is_also_a_retry(): void
    {
        $ticket = $this->makeTicket();
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success']])]);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertStatus(503);
        $this->assertSame('pending', $ticket->fresh()->status);
    }

    public function test_a_late_payment_for_a_cancelled_ticket_is_held_and_reported_not_applied(): void
    {
        $ticket = $this->makeTicket(['status' => 'failed', 'failure_reason' => 'cancelled']);
        $this->fakeTicketVerify($ticket);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame(['failed', null], [$ticket->fresh()->status, $ticket->fresh()->paid_at]);
        $event = $this->events()->sole();
        $this->assertSame(['needs_review', 'ticket_cancelled'], [$event->status, $event->reason]);
        $this->assertSame(['ticket.held'], $this->audits());
        $this->assertNotEmpty(array_filter($this->logged, fn ($l) => str_contains($l, 'marketplace.ticket.late_payment') && str_contains($l, $ticket->ticket_ref)));
        Queue::assertNothingPushed();
    }

    public function test_a_charge_for_an_already_refunded_ticket_is_a_no_op(): void
    {
        $ticket = $this->makeTicket(['status' => 'refunded', 'refunded_at' => now(), 'paid_at' => now()]);
        $this->fakeTicketVerify($ticket);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk()->assertJson(['status' => 'replayed']);
        $this->assertSame('refunded', $ticket->fresh()->status);
    }

    public function test_an_unknown_reference_is_ignored_by_the_ticket_path_and_held_by_the_invoice_path_as_before(): void
    {
        $this->fakeHttp();
        $this->makeTicket();
        $stranger = $this->makeTicket()->replicate()->forceFill(['paystack_reference' => 'dbt-unknown-reference']);

        $this->webhook($this->ticketChargeSuccess($stranger))->assertOk()->assertJson(['status' => 'held']);

        $event = $this->events()->sole();
        $this->assertSame(['unmatched', 'unknown_reference', 'payment'], [$event->status, $event->reason, $event->type]);
        $this->assertSame(2, TicketPayment::count());
        $this->assertSame(2, TicketPayment::where('status', 'pending')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_invoice_payments_still_settle_through_the_webhook_with_the_marketplace_on(): void
    {
        $this->fakeHttp();
        $owner = $this->makeOwner();
        $invoice = $this->makeInvoice($owner->currentTeam, ['total_cents' => 100000]);
        $attempt = $this->makeAttempt($invoice, [], $owner);
        $this->makeTicket();

        $this->webhook($this->chargeSuccess($attempt))->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('applied', $this->events()->sole()->status);
        Http::assertNothingSent(); // the invoice path does not verify: unchanged
        Queue::assertNothingPushed();
    }

    public function test_a_bad_signature_is_still_a_401_and_changes_nothing(): void
    {
        $ticket = $this->makeTicket();
        $this->fakeTicketVerify($ticket);

        $this->webhook($this->ticketChargeSuccess($ticket), 'deadbeef')->assertUnauthorized();

        $this->assertSame('pending', $ticket->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_while_the_marketplace_is_off_the_webhook_does_not_touch_tickets(): void
    {
        $ticket = $this->makeTicket();
        config(['billing.marketplace.enabled' => false]);
        $this->fakeTicketVerify($ticket);

        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertSame('unknown_reference', $this->events()->sole()->reason);
        Http::assertNothingSent();
    }

    public function test_the_end_to_end_flow_create_then_pay_then_status(): void
    {
        $this->fakeMarketplaceHttp();
        $account = $this->makePayoutAccount();
        $this->signed('POST', '/api/seminar-tickets', [
            'ticket_ref' => 'e2e-1', 'payout_account_id' => $account->id, 'buyer_email' => 'a@example.test',
            'amount_cents' => 20000, 'currency' => 'ZAR', 'description' => 'E2E',
        ])->assertCreated();
        $ticket = TicketPayment::where('ticket_ref', 'e2e-1')->sole();
        $this->signed('GET', '/api/seminar-tickets/e2e-1')->assertJsonPath('status', 'pending');

        $this->fakeTicketVerify($ticket);
        $this->webhook($this->ticketChargeSuccess($ticket))->assertOk();

        $this->signed('GET', '/api/seminar-tickets/e2e-1')->assertOk()
            ->assertJsonPath('status', 'paid')->assertJsonPath('paid_at', '2026-11-05T09:01:00+00:00');
        $this->signed('POST', '/api/seminar-tickets', [
            'ticket_ref' => 'e2e-1', 'payout_account_id' => $account->id, 'buyer_email' => 'a@example.test',
            'amount_cents' => 20000, 'currency' => 'ZAR', 'description' => 'E2E',
        ])->assertStatus(409)->assertJsonPath('error', 'already_paid');
    }
}
