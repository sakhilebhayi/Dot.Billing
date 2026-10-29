<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\SendMarketplaceCallback;
use App\Models\BillingAuditEvent;
use App\Models\PaymentEvent;
use App\Models\TicketPayment;
use App\Models\TicketRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

class TicketPartialRefundTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    private TicketPayment $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configureMarketplace();
        $this->fakeMarketplaceHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['status' => 'pending', 'id' => 7001]])]);
        Queue::fake();
        $this->ticket = $this->makePaidTicket();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function refund(array $body = [], ?string $ref = null): TestResponse
    {
        return $this->signed('POST', '/api/seminar-tickets/'.($ref ?? $this->ticket->ticket_ref).'/refund', $body === [] ? null : $body);
    }

    private function processed(array $data = []): void
    {
        $this->webhook($this->ticketRefundProcessed($this->ticket, $data))->assertOk();
    }

    private function audits(): array
    {
        return BillingAuditEvent::withoutGlobalScope('team')->orderBy('id')->pluck('action')->all();
    }

    // ---- asking for a partial refund ------------------------------------------------------------

    public function test_a_partial_refund_sends_the_amount_and_currency_to_paystack_and_waits_for_the_webhook(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk()
            ->assertJsonPath('status', 'paid')->assertJsonPath('refunded_cents', 0)->assertJsonPath('refund_requested', true)
            ->assertJsonPath('refund', ['refund_key' => 'r-1', 'amount_cents' => 5000, 'status' => 'pending']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.paystack.co/refund'
            && $r->data() === ['transaction' => $this->ticket->paystack_reference, 'amount' => 5000, 'currency' => 'ZAR']);

        $row = TicketRefund::sole();
        $this->assertSame([$this->ticket->id, 'r-1', 5000, 'pending', '7001', null], [$row->ticket_payment_id, $row->refund_key, $row->amount_cents, $row->status, $row->paystack_refund_id, $row->processed_at]);
        $this->assertSame(['paid', 0], [$this->ticket->fresh()->status, $this->ticket->fresh()->refunded_cents]);
        $this->assertSame(['ticket.refund_requested'], $this->audits());
        Queue::assertNothingPushed();
    }

    public function test_a_refund_with_no_amount_refunds_the_whole_ticket_and_sends_no_amount(): void
    {
        $this->refund()->assertOk()->assertJsonPath('refund', ['refund_key' => 'full', 'amount_cents' => 20000, 'status' => 'pending']);

        Http::assertSent(fn (Request $r) => $r->data() === ['transaction' => $this->ticket->paystack_reference]);
    }

    public function test_a_refund_with_no_amount_after_a_partial_one_refunds_exactly_what_remains(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();
        $this->processed(['amount' => 5000, 'id' => 7001]);
        $this->fakeMarketplaceHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['status' => 'pending', 'id' => 7002]])]);

        $this->refund()->assertOk()->assertJsonPath('refund', ['refund_key' => 'full', 'amount_cents' => 15000, 'status' => 'pending']);

        Http::assertSent(fn (Request $r) => $r->data() === ['transaction' => $this->ticket->paystack_reference, 'amount' => 15000, 'currency' => 'ZAR']);
    }

    public function test_an_amount_requires_a_refund_key(): void
    {
        $this->refund(['amount_cents' => 5000])->assertUnprocessable()->assertJsonValidationErrors('refund_key');

        Http::assertNothingSent();
        $this->assertSame(0, TicketRefund::count());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidBodies(): array
    {
        return [
            'zero' => [['amount_cents' => 0, 'refund_key' => 'k'], 'amount_cents'],
            'negative' => [['amount_cents' => -5, 'refund_key' => 'k'], 'amount_cents'],
            'string' => [['amount_cents' => '5000', 'refund_key' => 'k'], 'amount_cents'],
            'float' => [['amount_cents' => 50.5, 'refund_key' => 'k'], 'amount_cents'],
            'key too long' => [['amount_cents' => 5000, 'refund_key' => str_repeat('k', 65)], 'refund_key'],
            'key with a space' => [['amount_cents' => 5000, 'refund_key' => 'a b'], 'refund_key'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_refund_input_is_a_422_that_calls_nothing(array $body, string $field): void
    {
        $this->refund($body)->assertUnprocessable()->assertJsonValidationErrors($field);

        Http::assertNothingSent();
    }

    public function test_the_key_may_be_64_characters(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => str_repeat('k', 64)])->assertOk();
    }

    // ---- idempotency ----------------------------------------------------------------------------

    public function test_the_same_key_and_amount_again_is_the_same_refund_with_no_second_paystack_call(): void
    {
        $first = $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();
        $second = $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();

        $this->assertSame($first->json(), $second->json());
        Http::assertSentCount(1);
        $this->assertSame(1, TicketRefund::count());
        $this->assertSame(['ticket.refund_requested'], $this->audits());
    }

    public function test_the_same_key_after_it_was_processed_is_still_the_same_refund(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();
        $this->processed(['amount' => 5000, 'id' => 7001]);

        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk()
            ->assertJsonPath('refund.status', 'processed')->assertJsonPath('refunded_cents', 5000)->assertJsonPath('refund_requested', false);

        Http::assertSentCount(1);
        $this->assertSame(5000, $this->ticket->fresh()->refunded_cents);
    }

    public function test_the_same_key_with_a_different_amount_is_a_409(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();

        $this->refund(['amount_cents' => 6000, 'refund_key' => 'r-1'])->assertStatus(409)->assertJsonPath('error', 'refund_key_conflict');

        Http::assertSentCount(1);
        $this->assertSame(5000, TicketRefund::sole()->amount_cents);
    }

    public function test_different_keys_are_different_refunds(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-2'])->assertOk();

        Http::assertSentCount(2);
        $this->assertSame(2, TicketRefund::count());
    }

    public function test_keys_are_per_ticket(): void
    {
        $other = $this->makePaidTicket();

        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();
        $this->refund(['amount_cents' => 7000, 'refund_key' => 'r-1'], $other->ticket_ref)->assertOk();

        $this->assertSame(2, TicketRefund::count());
    }

    // ---- over-refund ----------------------------------------------------------------------------

    public function test_an_amount_above_the_ticket_is_a_422(): void
    {
        $this->refund(['amount_cents' => 20001, 'refund_key' => 'r-1'])->assertUnprocessable()->assertJsonPath('error', 'refund_exceeds_remaining');

        Http::assertNothingSent();
        $this->assertSame(0, TicketRefund::count());
    }

    public function test_the_whole_ticket_may_be_refunded_with_an_explicit_amount(): void
    {
        $this->refund(['amount_cents' => 20000, 'refund_key' => 'r-1'])->assertOk();

        Http::assertSent(fn (Request $r) => $r->data() === ['transaction' => $this->ticket->paystack_reference]);
    }

    public function test_an_amount_above_what_remains_after_a_processed_refund_is_a_422(): void
    {
        $this->refund(['amount_cents' => 15000, 'refund_key' => 'r-1'])->assertOk();
        $this->processed(['amount' => 15000, 'id' => 7001]);

        $this->refund(['amount_cents' => 5001, 'refund_key' => 'r-2'])->assertUnprocessable()->assertJsonPath('error', 'refund_exceeds_remaining');
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-2'])->assertOk();
    }

    public function test_refunds_that_are_still_pending_count_against_what_remains(): void
    {
        $this->refund(['amount_cents' => 15000, 'refund_key' => 'r-1'])->assertOk();

        $this->refund(['amount_cents' => 5001, 'refund_key' => 'r-2'])->assertUnprocessable();
        $this->refund()->assertOk()->assertJsonPath('refund.amount_cents', 5000);
        $this->refund(['amount_cents' => 1, 'refund_key' => 'r-3'])->assertUnprocessable();
    }

    public function test_a_keyless_full_refund_with_nothing_left_to_refund_is_a_422(): void
    {
        $this->refund(['amount_cents' => 20000, 'refund_key' => 'all'])->assertOk();

        $this->refund()->assertUnprocessable()->assertJsonPath('error', 'refund_exceeds_remaining');
        Http::assertSentCount(1);
    }

    // ---- applying the webhook -------------------------------------------------------------------

    public function test_a_processed_partial_refund_makes_the_ticket_partially_refunded_and_queues_the_callback(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();

        $this->webhook($this->ticketRefundProcessed($this->ticket, ['amount' => 5000, 'id' => 7001]))->assertOk()->assertJson(['status' => 'applied']);

        $ticket = $this->ticket->fresh();
        $this->assertSame(['partially_refunded', 5000, '2026-11-05T09:00:00+00:00'], [$ticket->status, $ticket->refunded_cents, $ticket->refunded_at->toIso8601String()]);
        $refund = TicketRefund::sole();
        $this->assertSame(['processed', '7001'], [$refund->status, $refund->paystack_refund_id]);
        $this->assertNotNull($refund->processed_at);
        $this->assertSame(['ticket.refund_requested', 'ticket.refunded'], $this->audits());

        Queue::assertPushed(SendMarketplaceCallback::class, 1);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload === [
            'event' => 'ticket.refunded', 'ticket_ref' => $ticket->ticket_ref, 'reference' => $ticket->paystack_reference,
            'amount_cents' => 20000, 'currency' => 'ZAR', 'refunded_at' => '2026-11-05T09:00:00+00:00',
            'refund_amount_cents' => 5000, 'refunded_total_cents' => 5000, 'refund_key' => 'r-1',
        ]);

        $this->signed('GET', "/api/seminar-tickets/{$ticket->ticket_ref}")->assertOk()->assertExactJson([
            'ticket_ref' => $ticket->ticket_ref, 'status' => 'partially_refunded', 'amount_cents' => 20000, 'platform_fee_cents' => 2000, 'recovery_cents' => 0,
            'currency' => 'ZAR', 'refunded_cents' => 5000, 'dispute_status' => null, 'paid_at' => $ticket->paid_at->toIso8601String(),
            'refunded_at' => '2026-11-05T09:00:00+00:00',
        ]);
    }

    public function test_refunds_add_up_and_the_ticket_is_refunded_only_when_the_total_equals_the_amount(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();
        $this->processed(['amount' => 5000, 'id' => 7001]);
        $this->refund(['amount_cents' => 7000, 'refund_key' => 'r-2'])->assertOk();
        $this->processed(['amount' => 7000, 'id' => 7002]);

        $this->assertSame(['partially_refunded', 12000], [$this->ticket->fresh()->status, $this->ticket->fresh()->refunded_cents]);

        $this->refund(['amount_cents' => 8000, 'refund_key' => 'r-3'])->assertOk();
        $this->processed(['amount' => 8000, 'id' => 7003]);

        $this->assertSame(['refunded', 20000], [$this->ticket->fresh()->status, $this->ticket->fresh()->refunded_cents]);
        Queue::assertPushed(SendMarketplaceCallback::class, 3);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload['refund_key'] === 'r-3' && $j->payload['refunded_total_cents'] === 20000 && $j->payload['amount_cents'] === 20000);

        // Nothing more can be refunded, and the earlier keys still answer.
        $this->refund(['amount_cents' => 1, 'refund_key' => 'r-4'])->assertUnprocessable();
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk()->assertJsonPath('refund.status', 'processed');
        $this->refund()->assertOk()->assertJsonPath('status', 'refunded');
    }

    public function test_a_replayed_refund_webhook_is_counted_once(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();
        $this->processed(['amount' => 5000, 'id' => 7001]);

        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->webhook($this->ticketRefundProcessed($this->ticket, ['amount' => 5000, 'id' => 7001]))->assertOk()->assertJson(['status' => 'replayed']);

        $this->assertSame(5000, $this->ticket->fresh()->refunded_cents);
        $this->assertSame('2026-11-05T09:00:00+00:00', $this->ticket->fresh()->refunded_at->toIso8601String());
        Queue::assertPushed(SendMarketplaceCallback::class, 1);
    }

    public function test_the_webhook_is_matched_to_the_pending_refund_of_the_same_amount_when_the_ids_differ(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();

        $this->webhook($this->ticketRefundProcessed($this->ticket, ['amount' => 5000, 'id' => 99999, 'refund_reference' => 'rf_other']))->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame(1, TicketRefund::count());
        $this->assertSame(['processed', '7001'], [TicketRefund::sole()->status, TicketRefund::sole()->paystack_refund_id]);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload['refund_key'] === 'r-1');
    }

    public function test_the_webhook_is_matched_by_paystacks_refund_id_when_amounts_repeat(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();
        $this->fakeMarketplaceHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['status' => 'pending', 'id' => 7002]])]);
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-2'])->assertOk();

        $this->webhook($this->ticketRefundProcessed($this->ticket, ['amount' => 5000, 'id' => 7002]))->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame(['pending', 'processed'], TicketRefund::orderBy('id')->pluck('status')->all());
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload['refund_key'] === 'r-2');
    }

    public function test_an_unmatchable_refund_when_two_pending_refunds_look_alike_is_held(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-2'])->assertOk();

        $this->webhook($this->ticketRefundProcessed($this->ticket, ['amount' => 5000, 'id' => 99999]))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame(0, $this->ticket->fresh()->refunded_cents);
        $this->assertSame('refund_ambiguous', PaymentEvent::withoutGlobalScope('team')->sole()->reason);
    }

    public function test_a_partial_refund_made_in_the_paystack_dashboard_is_recorded(): void
    {
        $this->webhook($this->ticketRefundProcessed($this->ticket, ['amount' => 3000, 'id' => 8123]))->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame(['partially_refunded', 3000], [$this->ticket->fresh()->status, $this->ticket->fresh()->refunded_cents]);
        $row = TicketRefund::sole();
        $this->assertSame(['paystack-8123', 3000, 'processed', '8123'], [$row->refund_key, $row->amount_cents, $row->status, $row->paystack_refund_id]);
        Queue::assertPushed(SendMarketplaceCallback::class, fn ($j) => $j->payload['refund_key'] === 'paystack-8123' && $j->payload['refund_amount_cents'] === 3000);

        $this->webhook($this->ticketRefundProcessed($this->ticket, ['amount' => 3000, 'id' => 8123]))->assertOk()->assertJson(['status' => 'replayed']);
        $this->assertSame(3000, $this->ticket->fresh()->refunded_cents);
    }

    public function test_a_refund_failed_webhook_frees_the_amount_and_lets_the_same_key_be_asked_for_again(): void
    {
        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk();

        $this->webhook(['event' => 'refund.failed', 'data' => ['status' => 'failed', 'transaction_reference' => $this->ticket->paystack_reference, 'id' => 7001, 'amount' => 5000, 'currency' => 'ZAR']])
            ->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame(['failed'], TicketRefund::pluck('status')->all());
        $this->assertSame(['ticket.refund_requested', 'ticket.refund_failed'], $this->audits());
        $this->assertSame(['paid', 0], [$this->ticket->fresh()->status, $this->ticket->fresh()->refunded_cents]);
        Queue::assertNothingPushed();

        $this->refund(['amount_cents' => 5000, 'refund_key' => 'r-1'])->assertOk()->assertJsonPath('refund.status', 'pending');
        Http::assertSentCount(2);
        $this->assertSame(1, TicketRefund::count());

        $this->refund(['amount_cents' => 6000, 'refund_key' => 'r-1'])->assertStatus(409);
    }

    public function test_refund_failed_for_something_that_is_not_ours_is_ignored(): void
    {
        $this->webhook(['event' => 'refund.failed', 'data' => ['transaction_reference' => 'dbi1-someone', 'id' => 5]])->assertOk()->assertJson(['status' => 'ignored']);
        $this->webhook(['event' => 'refund.failed', 'data' => ['transaction_reference' => $this->ticket->paystack_reference, 'id' => 5, 'amount' => 1234]])->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(0, PaymentEvent::withoutGlobalScope('team')->count());
    }

    public function test_a_partially_refunded_ticket_cannot_be_cancelled_or_paid_again(): void
    {
        $this->processed(['amount' => 3000, 'id' => 8123]);

        $this->signed('POST', "/api/seminar-tickets/{$this->ticket->ticket_ref}/cancel")->assertStatus(409)->assertJsonPath('error', 'ticket_not_cancellable');
        $this->fakeTicketVerify($this->ticket);
        $this->webhook($this->ticketChargeSuccess($this->ticket))->assertOk()->assertJson(['status' => 'replayed']);

        $this->assertSame('partially_refunded', $this->ticket->fresh()->status);
    }
}
