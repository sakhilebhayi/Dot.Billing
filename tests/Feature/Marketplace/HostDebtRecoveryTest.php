<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\SendMarketplaceCallback;
use App\Models\BillingAuditEvent;
use App\Models\HostDebt;
use App\Models\HostDebtRecovery;
use App\Models\PayoutAccount;
use App\Models\TicketPayment;
use App\Services\Marketplace\HostDebts;
use App\Services\Marketplace\MarketplaceCallbackClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

/**
 * Chargeback recovery: a lost dispute opens a debt, later tickets for that host carry a larger fee (under the
 * accepted term), and a verified-paid ticket credits it. Worked example from docs/seminar-payments.md: a R200 ticket,
 * 10% fee R20, host share R180, host owes R100, recovery min(100, 90) = R90, fee on that ticket R110.
 */
class HostDebtRecoveryTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    private PayoutAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configureMarketplace();
        $this->fakeAllPaystack();
        Queue::fake();
        $this->account = $this->makePayoutAccount(['host_ref' => 'host-1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Initialise answers with a checkout; verify answers for whichever ticket the reference names. */
    private function fakeAllPaystack(): void
    {
        $this->fakeMarketplaceHttp([
            'api.paystack.co/transaction/verify/*' => function (Request $r) {
                $ticket = TicketPayment::where('paystack_reference', basename($r->url()))->firstOrFail();

                return Http::response($this->ticketVerifyBody($ticket));
            },
        ]);
    }

    /** A debt of $cents on $account, owed for a ticket that was lost to a chargeback. */
    private function debt(int $cents, ?PayoutAccount $account = null, array $attributes = []): HostDebt
    {
        $account ??= $this->account;
        $lost = $this->makeTicket(['status' => TicketPayment::CHARGEBACK, 'paid_at' => now()], $account);

        return HostDebt::create(array_merge([
            'payout_account_id' => $account->id, 'ticket_payment_id' => $lost->id, 'currency' => 'ZAR',
            'amount_cents' => $cents, 'recovered_cents' => 0, 'status' => HostDebt::OPEN,
        ], $attributes));
    }

    /** A paid ticket for this test's host. */
    private function paid(array $attributes = []): TicketPayment
    {
        return $this->makeTicket(array_merge(['status' => TicketPayment::PAID, 'paid_at' => now(), 'paystack_transaction_id' => '5550001'], $attributes), $this->account);
    }

    private function create(string $ref, int $amount = 20000, ?PayoutAccount $account = null)
    {
        return $this->signed('POST', '/api/seminar-tickets', [
            'ticket_ref' => $ref, 'payout_account_id' => ($account ?? $this->account)->id, 'buyer_email' => 'a@example.test',
            'amount_cents' => $amount, 'currency' => 'ZAR', 'description' => 'Masterclass',
        ]);
    }

    private function ticket(string $ref): TicketPayment
    {
        return TicketPayment::where('ticket_ref', $ref)->firstOrFail();
    }

    private function pay(TicketPayment $ticket)
    {
        return $this->webhook($this->ticketChargeSuccess($ticket));
    }

    private function refund(TicketPayment $ticket, int $cents, string $reference)
    {
        return $this->webhook($this->ticketRefundProcessed($ticket, ['amount' => $cents, 'refund_reference' => $reference]));
    }

    /** @return list<array<string, mixed>> */
    private function callbacks(string $prefix = 'host_debt.'): array
    {
        return Queue::pushed(SendMarketplaceCallback::class)
            ->map(fn ($job) => $job->payload)->filter(fn ($p) => str_starts_with($p['event'], $prefix))->values()->all();
    }

    private function audits(string $action): int
    {
        return BillingAuditEvent::withoutGlobalScope('team')->where('action', $action)->count();
    }

    // ---- opening a debt on a lost dispute ----------------------------------------------------------

    private function dispute(TicketPayment $ticket, string $resolution, string $id = '358950'): void
    {
        $this->webhook($this->disputeEvent('charge.dispute.create', $ticket->paystack_reference, ['id' => $id]))->assertOk();
        $this->webhook($this->disputeEvent('charge.dispute.resolve', $ticket->paystack_reference, ['id' => $id, 'resolution' => $resolution]))->assertOk();
    }

    public function test_a_lost_dispute_opens_one_debt_for_the_host_share_and_tells_infodot(): void
    {
        $ticket = $this->paid();

        $this->dispute($ticket, 'merchant-accepted');

        $debt = HostDebt::sole();
        // R200.00 ticket - R20.00 platform fee - R0 refunded = R180.00: the host share InfoDot lost.
        $this->assertSame([$this->account->id, $ticket->id, 'ZAR', 18000, 0, 'open'], [
            $debt->payout_account_id, $debt->ticket_payment_id, $debt->currency, $debt->amount_cents, $debt->recovered_cents, $debt->status,
        ]);
        $this->assertSame('chargeback', $ticket->fresh()->status);
        $this->assertSame(1, $this->audits('host_debt.opened'));

        $this->assertSame([[
            'event' => 'host_debt.opened', 'ticket_ref' => $ticket->ticket_ref, 'debt_id' => $debt->id, 'payout_account_id' => $this->account->id,
            'host_ref' => 'host-1', 'currency' => 'ZAR', 'amount_cents' => 18000, 'recovered_cents' => 0, 'remaining_cents' => 18000,
            'opened_at' => '2026-11-05T09:00:00+00:00',
        ]], $this->callbacks());
    }

    public function test_replaying_the_resolve_or_a_second_lost_dispute_never_opens_a_second_debt(): void
    {
        $ticket = $this->paid();
        $this->dispute($ticket, 'merchant-accepted');

        $this->webhook($this->disputeEvent('charge.dispute.resolve', $ticket->paystack_reference, ['resolution' => 'merchant-accepted']))->assertOk()->assertJson(['status' => 'replayed']);
        $this->dispute($ticket, 'merchant-accepted', id: '999001');

        $this->assertSame(1, HostDebt::count());
        $this->assertSame(1, $this->audits('host_debt.opened'));
        $this->assertCount(1, $this->callbacks());
    }

    public function test_a_won_or_unclassified_dispute_opens_no_debt(): void
    {
        $won = $this->paid();
        $other = $this->paid();
        $open = $this->paid();

        $this->dispute($won, 'declined');
        $this->dispute($other, 'something-new', id: '2');
        $this->webhook($this->disputeEvent('charge.dispute.create', $open->paystack_reference, ['id' => '3']))->assertOk();

        $this->assertSame(0, HostDebt::count());
        $this->assertSame([], $this->callbacks());
    }

    public function test_what_was_already_refunded_is_not_owed_twice(): void
    {
        $ticket = $this->paid(['refunded_cents' => 5000, 'status' => 'partially_refunded']);

        $this->dispute($ticket, 'merchant-accepted');

        // 20000 - 2000 fee - 5000 already back to the attendee
        $this->assertSame(13000, HostDebt::sole()->amount_cents);
    }

    public function test_a_fully_refunded_ticket_that_is_lost_leaves_nothing_to_owe(): void
    {
        $ticket = $this->paid(['refunded_cents' => 20000, 'status' => 'refunded']);

        $this->dispute($ticket, 'merchant-accepted');

        $this->assertSame(0, HostDebt::count());
        $this->assertSame(1, $this->audits('host_debt.not_opened'));
        $this->assertSame([], $this->callbacks());
    }

    public function test_a_chargeback_on_a_ticket_that_carried_recovery_still_owes_the_whole_host_share(): void
    {
        $earlier = $this->debt(9000);
        $this->create('tkt-r');
        $ticket = $this->ticket('tkt-r');
        $this->pay($ticket)->assertOk();
        $this->assertSame('cleared', $earlier->fresh()->status);

        $this->dispute($ticket->fresh(), 'merchant-accepted');

        // 20000 - 2000 platform fee. The 9000 recovered for the earlier debt is lost with the chargeback, so it is
        // part of this debt, and the earlier debt stays cleared.
        $this->assertSame(18000, HostDebt::where('ticket_payment_id', $ticket->id)->sole()->amount_cents);
        $this->assertSame('cleared', $earlier->fresh()->status);
    }

    // ---- the extra fee on a new ticket -------------------------------------------------------------

    public function test_the_worked_example_a_r200_ticket_with_r100_owed_carries_r90_recovery(): void
    {
        $this->debt(10000);

        $this->create('tkt-w')->assertCreated();

        $ticket = $this->ticket('tkt-w');
        // host share 18000, 50% = 9000, owed 10000: recovery is the smaller, 9000. Fee on this ticket 2000 + 9000 = 11000.
        $this->assertSame([2000, 9000, 11000, 20000], [$ticket->platform_fee_cents, $ticket->recovery_cents, $ticket->chargeCents(), $ticket->amount_cents]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize')
            && $r->data()['transaction_charge'] === 11000 && is_int($r->data()['transaction_charge'])
            && $r->data()['amount'] === 20000 && $r->data()['bearer'] === 'subaccount');

        $this->signed('GET', '/api/seminar-tickets/tkt-w')->assertOk()->assertJson(['platform_fee_cents' => 2000, 'recovery_cents' => 9000]);
        $this->assertSame(0, HostDebt::sole()->recovered_cents, 'nothing is credited until the ticket is verified paid');
        $this->assertSame(1, BillingAuditEvent::withoutGlobalScope('team')->where('action', 'ticket.created')->get()->filter(fn ($e) => $e->meta['recovery_cents'] === 9000)->count());
    }

    public function test_a_host_with_no_debt_pays_only_the_platform_fee(): void
    {
        $this->create('tkt-n')->assertCreated();

        $this->assertSame([2000, 0], [$this->ticket('tkt-n')->platform_fee_cents, $this->ticket('tkt-n')->recovery_cents]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize') && $r->data()['transaction_charge'] === 2000);
    }

    /** @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: int}> debt, amount, cap, expected recovery, expected fee */
    public static function recoveryCases(): array
    {
        return [
            'debt smaller than half the host share' => [5000, 20000, 50, 5000, 2000],
            'debt larger: capped at half the host share' => [10000, 20000, 50, 9000, 2000],
            'ticket smaller than the debt' => [100000, 5000, 50, 2250, 500],
            'odd host share rounds the cap down' => [100000, 1003, 50, 451, 100],
            'host share 904 halves exactly' => [100000, 1005, 50, 452, 101],
            'a 30% cap' => [100000, 20000, 30, 5400, 2000],
            'cap 0 switches recovery off' => [100000, 20000, 0, 0, 2000],
            'a 100% cap takes the whole host share' => [100000, 20000, 100, 18000, 2000],
            'one cent of debt' => [1, 20000, 50, 1, 2000],
        ];
    }

    #[DataProvider('recoveryCases')]
    public function test_recovery_is_the_smaller_of_what_is_owed_and_the_capped_host_share(int $debt, int $amount, int $cap, int $recovery, int $fee): void
    {
        config(['billing.marketplace.recovery_cap_percent' => $cap]);
        $this->debt($debt);

        $this->create('tkt-c', $amount)->assertCreated();

        $ticket = $this->ticket('tkt-c');
        $this->assertSame([$fee, $recovery], [$ticket->platform_fee_cents, $ticket->recovery_cents]);
        $this->assertLessThanOrEqual($ticket->amount_cents, $ticket->chargeCents());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize') && $r->data()['transaction_charge'] === $fee + $recovery);
    }

    public function test_nothing_is_taken_without_the_accepted_current_term(): void
    {
        $this->debt(10000);
        $bare = $this->makePayoutAccount(['host_ref' => 'host-bare'], agreed: false);
        $this->debt(10000, $bare);
        $this->acceptAgreement($bare, 'v0');

        $quote = fn (PayoutAccount $a) => DB::transaction(fn () => app(HostDebts::class)->quote($a, 20000, 2000, 'ZAR'));

        $this->assertSame(9000, $quote($this->account));
        $this->assertSame(0, $quote($bare), 'an older version of the term does not count');

        $this->acceptAgreement($bare);
        $this->assertSame(9000, $quote($bare));
    }

    public function test_only_this_hosts_open_debts_in_the_ticket_currency_are_recovered(): void
    {
        $other = $this->makePayoutAccount(['host_ref' => 'host-2']);
        $this->debt(10000, $other);
        $this->debt(10000, null, ['currency' => 'USD']);
        $this->debt(10000, null, ['status' => HostDebt::WRITTEN_OFF]);
        $this->debt(10000, null, ['status' => HostDebt::CLEARED, 'recovered_cents' => 10000]);

        $this->create('tkt-o')->assertCreated();

        $this->assertSame(0, $this->ticket('tkt-o')->recovery_cents);
    }

    public function test_two_tickets_created_back_to_back_cannot_reserve_more_than_is_owed(): void
    {
        $debt = $this->debt(10000);

        $this->create('tkt-a')->assertCreated();
        $this->create('tkt-b')->assertCreated();
        $this->create('tkt-c')->assertCreated();

        // 9000 is reserved by the first, so the second can reserve only the 1000 that is left, the third nothing.
        $this->assertSame([9000, 1000, 0], [$this->ticket('tkt-a')->recovery_cents, $this->ticket('tkt-b')->recovery_cents, $this->ticket('tkt-c')->recovery_cents]);
        $this->assertSame(10000, TicketPayment::sum('recovery_cents'));

        $this->signed('GET', '/api/payout-accounts/'.$this->account->id.'/debts')->assertOk()->assertJson(['in_flight_recovery_cents' => 10000, 'open_remaining_cents' => 10000]);

        foreach (['tkt-b', 'tkt-a', 'tkt-c'] as $ref) {
            $this->pay($this->ticket($ref))->assertOk()->assertJson(['status' => 'applied']);
        }

        $debt->refresh();
        $this->assertSame([10000, 'cleared'], [$debt->recovered_cents, $debt->status], 'exactly the debt, never more');
    }

    public function test_the_debt_rows_are_locked_while_a_ticket_is_priced(): void
    {
        $this->debt(10000);
        DB::enableQueryLog();

        $this->create('tkt-l')->assertCreated();

        // SQLite ignores row locks, so what is proven here is that the locking read is requested on the debt table
        // before the ticket is written, inside the creating transaction.
        $log = collect(DB::getQueryLog())->pluck('query');
        $this->assertTrue($log->contains(fn ($q) => str_contains($q, 'host_debts') && str_contains($q, 'where "payout_account_id"')));
    }

    public function test_a_cancelled_ticket_gives_its_reservation_back_and_the_debt_is_unchanged(): void
    {
        $debt = $this->debt(10000);
        $this->create('tkt-a')->assertCreated();
        $this->create('tkt-b')->assertCreated();
        $this->assertSame(1000, $this->ticket('tkt-b')->recovery_cents);

        $this->signed('POST', '/api/seminar-tickets/tkt-a/cancel')->assertOk()->assertJson(['status' => 'failed']);

        $this->assertSame(0, $debt->fresh()->recovered_cents);
        $this->create('tkt-c')->assertCreated();
        $this->assertSame(9000, $this->ticket('tkt-c')->recovery_cents, 'the cancelled ticket no longer reserves anything');
        $this->assertSame(0, HostDebtRecovery::count());
    }

    // ---- credited only when verified paid, once -----------------------------------------------------

    public function test_a_verified_payment_credits_the_recovery_once_even_if_the_webhook_is_replayed(): void
    {
        $debt = $this->debt(10000);
        $this->create('tkt-p')->assertCreated();
        $ticket = $this->ticket('tkt-p');

        $this->pay($ticket)->assertOk()->assertJson(['status' => 'applied']);
        $this->pay($ticket)->assertOk()->assertJson(['status' => 'replayed']);
        $this->pay($ticket)->assertOk()->assertJson(['status' => 'replayed']);

        $debt->refresh();
        $this->assertSame([9000, 'open', 1000], [$debt->recovered_cents, $debt->status, $debt->remainingCents()]);
        $this->assertSame(9000, $ticket->fresh()->recovery_applied_cents);
        $this->assertSame(1, HostDebtRecovery::count());
        $this->assertSame(1, $this->audits('host_debt.recovered'));
        $this->assertSame([], $this->callbacks(), 'a partly recovered debt has no event');
    }

    public function test_the_debt_clears_at_zero_remaining_and_infodot_is_told_once(): void
    {
        $debt = $this->debt(9000);
        $this->create('tkt-p')->assertCreated();
        $ticket = $this->ticket('tkt-p');

        $this->pay($ticket)->assertOk();
        $this->pay($ticket)->assertOk();

        $debt->refresh();
        $this->assertSame([9000, 'cleared'], [$debt->recovered_cents, $debt->status]);
        $this->assertSame('2026-11-05T09:00:00+00:00', $debt->cleared_at->toIso8601String());
        $this->assertSame(1, $this->audits('host_debt.cleared'));
        $this->assertSame([[
            'event' => 'host_debt.cleared', 'ticket_ref' => $debt->ticket->ticket_ref, 'debt_id' => $debt->id, 'payout_account_id' => $this->account->id,
            'host_ref' => 'host-1', 'currency' => 'ZAR', 'amount_cents' => 9000, 'recovered_cents' => 9000, 'remaining_cents' => 0,
            'cleared_at' => '2026-11-05T09:00:00+00:00',
        ]], $this->callbacks());

        // With the debt cleared, the next ticket is back to the platform fee only.
        $this->create('tkt-next')->assertCreated();
        $this->assertSame(0, $this->ticket('tkt-next')->recovery_cents);
    }

    public function test_one_ticket_pays_down_the_oldest_debts_first_never_above_what_each_owes(): void
    {
        $first = $this->debt(4000);
        $second = $this->debt(20000);
        $this->create('tkt-m')->assertCreated();
        $ticket = $this->ticket('tkt-m');
        $this->assertSame(9000, $ticket->recovery_cents);

        $this->pay($ticket)->assertOk();

        $this->assertSame([4000, 'cleared'], [$first->fresh()->recovered_cents, $first->fresh()->status]);
        $this->assertSame([5000, 'open'], [$second->fresh()->recovered_cents, $second->fresh()->status]);
        $this->assertSame(9000, HostDebtRecovery::sum('amount_cents'));
    }

    public function test_a_payment_that_does_not_verify_credits_nothing(): void
    {
        $debt = $this->debt(10000);
        $this->create('tkt-v')->assertCreated();
        $ticket = $this->ticket('tkt-v');

        $this->fakeTicketVerify($ticket, ['status' => 'failed']);
        $this->pay($ticket)->assertOk()->assertJson(['status' => 'held']);
        $this->fakeTicketVerify($ticket, ['amount' => 100]);
        $this->pay($ticket)->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame([0, 'pending', 0], [$debt->fresh()->recovered_cents, $ticket->fresh()->status, $ticket->fresh()->recovery_applied_cents]);
        $this->assertSame(0, HostDebtRecovery::count());
    }

    public function test_a_payment_for_a_cancelled_ticket_is_held_and_credits_nothing(): void
    {
        $debt = $this->debt(10000);
        $this->create('tkt-x')->assertCreated();
        $this->signed('POST', '/api/seminar-tickets/tkt-x/cancel')->assertOk();

        $this->pay($this->ticket('tkt-x'))->assertOk()->assertJson(['status' => 'held']);

        $this->assertSame(0, $debt->fresh()->recovered_cents);
    }

    public function test_a_debt_written_off_while_a_ticket_was_in_flight_is_not_credited_and_is_flagged(): void
    {
        $debt = $this->debt(10000);
        $this->create('tkt-w')->assertCreated();
        $this->signed('POST', '/api/host-debts/'.$debt->id.'/write-off', ['note' => 'Goodwill', 'written_off_by_ref' => 'mod-1'])->assertOk();

        $ticket = $this->ticket('tkt-w');
        $this->pay($ticket)->assertOk()->assertJson(['status' => 'applied']);

        $this->assertSame(0, $debt->fresh()->recovered_cents);
        $this->assertSame([9000, 0], [$ticket->fresh()->recovery_cents, $ticket->fresh()->recovery_applied_cents]);
        $this->assertSame(1, $this->audits('host_debt.recovery_unapplied'));
        $this->assertSame('written_off', $debt->fresh()->status);
    }

    // ---- refunds give the recovery back -----------------------------------------------------------

    public function test_a_full_refund_gives_the_whole_recovery_back_and_reopens_a_cleared_debt(): void
    {
        $debt = $this->debt(9000);
        $this->create('tkt-r')->assertCreated();
        $ticket = $this->ticket('tkt-r');
        $this->pay($ticket)->assertOk();
        $this->assertSame('cleared', $debt->fresh()->status);

        $this->refund($ticket, 20000, 'rf-1')->assertOk()->assertJson(['status' => 'applied']);
        $this->refund($ticket, 20000, 'rf-1')->assertOk()->assertJson(['status' => 'replayed']);

        $debt->refresh();
        $this->assertSame([0, 'open', null], [$debt->recovered_cents, $debt->status, $debt->cleared_at]);
        $this->assertSame([9000, 9000], [$ticket->fresh()->recovery_applied_cents, $ticket->fresh()->recovery_reversed_cents]);
        $this->assertSame(9000, HostDebtRecovery::sole()->reversed_cents);
        $this->assertSame(1, $this->audits('host_debt.recovery_reversed'));

        $events = array_column($this->callbacks(), 'event');
        $this->assertSame(['host_debt.cleared', 'host_debt.reopened'], $events);
        $this->assertSame(9000, $this->callbacks()[1]['remaining_cents']);
    }

    public function test_partial_refunds_give_back_in_proportion_and_never_more_than_was_credited(): void
    {
        $debt = $this->debt(50000);
        $this->create('tkt-r')->assertCreated();
        $ticket = $this->ticket('tkt-r');
        $this->pay($ticket)->assertOk();
        $this->assertSame(9000, $debt->fresh()->recovered_cents);

        $this->refund($ticket, 5000, 'rf-1')->assertOk(); // 5000/20000 of 9000 = 2250
        $this->assertSame(6750, $debt->fresh()->recovered_cents);

        $this->refund($ticket, 5000, 'rf-2')->assertOk(); // 10000/20000 of 9000 = 4500 in all
        $this->assertSame(4500, $debt->fresh()->recovered_cents);

        $this->refund($ticket, 10000, 'rf-3')->assertOk(); // the rest of the ticket: everything is back
        $this->assertSame(0, $debt->fresh()->recovered_cents);
        $this->assertSame(9000, $ticket->fresh()->recovery_reversed_cents);
        $this->assertSame('open', $debt->fresh()->status);
    }

    public function test_a_refund_on_a_ticket_with_no_recovery_touches_no_debt(): void
    {
        $debt = $this->debt(10000, null, ['recovered_cents' => 3000]);
        $plain = $this->paid();
        $this->refund($plain, 20000, 'rf-9')->assertOk();

        $this->assertSame(3000, $debt->fresh()->recovered_cents);
        $this->assertSame(0, $this->audits('host_debt.recovery_reversed'));
    }

    public function test_a_refund_after_a_write_off_leaves_the_written_off_debt_alone(): void
    {
        $debt = $this->debt(20000);
        $this->create('tkt-r')->assertCreated();
        $ticket = $this->ticket('tkt-r');
        $this->pay($ticket)->assertOk();
        $this->signed('POST', '/api/host-debts/'.$debt->id.'/write-off', ['note' => 'Goodwill', 'written_off_by_ref' => 'mod-1'])->assertOk();

        $this->refund($ticket, 20000, 'rf-1')->assertOk();

        $debt->refresh();
        $this->assertSame(['written_off', 9000], [$debt->status, $debt->recovered_cents]);
        $this->assertSame(9000, $ticket->fresh()->recovery_reversed_cents);
    }

    // ---- write-off and listing --------------------------------------------------------------------

    public function test_a_moderator_can_write_a_debt_off_and_it_is_recorded_and_reported(): void
    {
        $debt = $this->debt(10000, null, ['recovered_cents' => 2500]);

        $this->signed('POST', '/api/host-debts/'.$debt->id.'/write-off', ['note' => 'Host disputed the chargeback; goodwill.', 'written_off_by_ref' => 'mod-9'])
            ->assertOk()
            ->assertJsonFragment([
                'id' => $debt->id, 'status' => 'written_off', 'amount_cents' => 10000, 'recovered_cents' => 2500, 'remaining_cents' => 0,
                'written_off_cents' => 7500, 'written_off_by_ref' => 'mod-9', 'note' => 'Host disputed the chargeback; goodwill.',
            ]);

        $debt->refresh();
        $this->assertSame(['written_off', 'mod-9'], [$debt->status, $debt->written_off_by_ref]);
        $this->assertSame(1, $this->audits('host_debt.written_off'));
        $this->assertSame(['host_debt.written_off'], array_column($this->callbacks(), 'event'));
        $this->assertSame(7500, $this->callbacks()[0]['written_off_cents']);
        $this->assertSame('mod-9', $this->callbacks()[0]['written_off_by_ref']);

        // Nothing more is taken for it.
        $this->create('tkt-after')->assertCreated();
        $this->assertSame(0, $this->ticket('tkt-after')->recovery_cents);
    }

    public function test_writing_off_twice_changes_nothing_and_sends_one_event(): void
    {
        $debt = $this->debt(10000);
        $body = ['note' => 'First', 'written_off_by_ref' => 'mod-1'];

        $this->signed('POST', '/api/host-debts/'.$debt->id.'/write-off', $body)->assertOk();
        $this->signed('POST', '/api/host-debts/'.$debt->id.'/write-off', ['note' => 'Second', 'written_off_by_ref' => 'mod-2'])->assertOk()
            ->assertJsonFragment(['written_off_by_ref' => 'mod-1', 'note' => 'First']);

        $this->assertSame(1, $this->audits('host_debt.written_off'));
        $this->assertCount(1, $this->callbacks());
    }

    public function test_only_an_open_debt_can_be_written_off_and_the_input_is_checked(): void
    {
        $cleared = $this->debt(10000, null, ['status' => HostDebt::CLEARED, 'recovered_cents' => 10000]);
        $open = $this->debt(10000);

        $this->signed('POST', '/api/host-debts/'.$cleared->id.'/write-off', ['note' => 'x', 'written_off_by_ref' => 'm'])->assertStatus(409)->assertJson(['error' => 'debt_not_open']);
        $this->signed('POST', '/api/host-debts/999999/write-off', ['note' => 'x', 'written_off_by_ref' => 'm'])->assertNotFound()->assertJson(['error' => 'not_found']);
        $this->signed('POST', '/api/host-debts/'.$open->id.'/write-off', ['written_off_by_ref' => 'm'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->signed('POST', '/api/host-debts/'.$open->id.'/write-off', ['note' => 'x'])->assertStatus(422)->assertJsonValidationErrors('written_off_by_ref');
        $this->signed('POST', '/api/host-debts/'.$open->id.'/write-off', ['note' => str_repeat('a', 501), 'written_off_by_ref' => 'm'])->assertStatus(422)->assertJsonValidationErrors('note');

        $this->assertSame('open', $open->fresh()->status);
        $this->assertSame('cleared', $cleared->fresh()->status);
    }

    public function test_the_debt_list_shows_amount_recovered_remaining_and_status_for_that_host_only(): void
    {
        $a = $this->debt(10000, null, ['recovered_cents' => 4000]);
        $b = $this->debt(6000, null, ['status' => HostDebt::CLEARED, 'recovered_cents' => 6000]);
        $this->debt(7777, $this->makePayoutAccount(['host_ref' => 'host-2']));

        $response = $this->signed('GET', '/api/payout-accounts/'.$this->account->id.'/debts')->assertOk();

        $response->assertJson([
            'payout_account_id' => $this->account->id,
            'agreement' => ['current_version' => 'v1', 'accepted' => true],
            'open_remaining_cents' => 6000,
            'in_flight_recovery_cents' => 0,
        ]);
        $this->assertSame(
            [[$a->id, 10000, 4000, 6000, 'open'], [$b->id, 6000, 6000, 0, 'cleared']],
            collect($response->json('debts'))->map(fn ($d) => [$d['id'], $d['amount_cents'], $d['recovered_cents'], $d['remaining_cents'], $d['status']])->all(),
        );
        $this->signed('GET', '/api/payout-accounts/999999/debts')->assertNotFound();
    }

    public function test_the_debt_list_says_when_the_host_has_not_accepted_the_current_term(): void
    {
        $bare = $this->makePayoutAccount(['host_ref' => 'host-bare'], agreed: false);

        $this->signed('GET', '/api/payout-accounts/'.$bare->id.'/debts')->assertOk()->assertJson(['agreement' => ['accepted' => false], 'debts' => []]);
    }

    public function test_the_debt_endpoints_are_404_while_the_marketplace_is_off(): void
    {
        $debt = $this->debt(10000);
        config(['billing.marketplace.enabled' => false]);

        $this->signed('GET', '/api/payout-accounts/'.$this->account->id.'/debts')->assertNotFound();
        $this->signed('POST', '/api/host-debts/'.$debt->id.'/write-off', ['note' => 'x', 'written_off_by_ref' => 'm'])->assertNotFound();
        $this->assertSame('open', $debt->fresh()->status);
    }

    // ---- callbacks keep the existing conventions -------------------------------------------------------

    public function test_host_debt_events_are_signed_like_every_callback_with_an_idempotency_key(): void
    {
        $debt = $this->debt(10000);
        $this->fakeHttp(['infodot.example/*' => Http::response('', 200)]);

        foreach ([HostDebts::OPENED, HostDebts::CLEARED, HostDebts::WRITTEN_OFF] as $event) {
            $debt->forceFill(['cleared_at' => now(), 'written_off_at' => now()])->save();
            app(MarketplaceCallbackClient::class)->hostDebt($event, $debt->fresh());
        }

        $payloads = $this->callbacks();
        $this->assertSame(['host_debt.opened', 'host_debt.cleared', 'host_debt.written_off'], array_column($payloads, 'event'));

        $client = app(MarketplaceCallbackClient::class);
        $keys = [];
        foreach ($payloads as $payload) {
            $this->assertSame(MarketplaceCallbackClient::DONE, $client->deliver($payload));
        }
        Http::assertSent(function (Request $r) use (&$keys) {
            $body = (string) $r->body();
            $timestamp = $r->header('X-InfoDot-Timestamp')[0];
            $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->callbackSecret), $r->header('X-InfoDot-Signature')[0]);
            $this->assertSame('billing', $r->header('X-InfoDot-Platform')[0]);
            $keys[] = $r->header('Idempotency-Key')[0];

            return true;
        });

        $ref = $debt->ticket->ticket_ref;
        $this->assertSame([
            "host_debt.opened:{$ref}:2026-11-05T09:00:00+00:00",
            "host_debt.cleared:{$ref}:2026-11-05T09:00:00+00:00",
            "host_debt.written_off:{$ref}:2026-11-05T09:00:00+00:00",
        ], $keys);
    }
}
