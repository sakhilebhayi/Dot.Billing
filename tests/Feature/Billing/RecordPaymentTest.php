<?php

namespace Tests\Feature\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingAuditEvent;
use App\Models\BillingPayment;
use App\Models\DunningCase;
use App\Models\PaymentEvent;
use App\Services\Billing\RecordPayment;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class RecordPaymentTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private RecordPayment $payments;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->payments = app(RecordPayment::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reason(callable $fn): string
    {
        try {
            $fn();
        } catch (BillingRuleViolation $e) {
            return $e->reason;
        }

        $this->fail('Expected a BillingRuleViolation.');
    }

    private function invoice(int $total = 100000, array $extra = [])
    {
        $owner = $this->makeOwner();

        return $this->makeInvoice($owner->currentTeam, ['total_cents' => $total] + $extra);
    }

    public function test_a_full_payment_marks_the_invoice_paid(): void
    {
        $invoice = $this->invoice(100000);

        $result = $this->payments->apply($invoice, new Money(100000, 'ZAR'), method: 'eft');

        $this->assertFalse($result->replayed);
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(100000, $invoice->amount_paid_cents);
        $this->assertTrue($invoice->paid_at->equalTo(now()));
        $this->assertSame('succeeded', $result->payment->status);
        $this->assertSame(100000, $result->payment->amount_cents);
        $this->assertSame('eft', $result->payment->method);
        $this->assertSame($result->payment->id, $result->event->payment_id);
    }

    public function test_partial_payments_accumulate_and_only_the_last_one_closes_the_invoice(): void
    {
        $invoice = $this->invoice(100000);

        $this->payments->apply($invoice, new Money(30000, 'ZAR'));
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame(30000, $invoice->fresh()->amount_paid_cents);

        $this->payments->apply($invoice->fresh(), new Money(30000, 'ZAR'));
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame(60000, $invoice->fresh()->amount_paid_cents);
        $this->assertSame(40000, $invoice->fresh()->balanceMoney()->cents);

        $this->payments->apply($invoice->fresh(), new Money(40000, 'ZAR'));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);
    }

    public function test_an_overpayment_is_rejected_and_nothing_is_recorded(): void
    {
        $invoice = $this->invoice(100000, ['amount_paid_cents' => 90000]);

        $this->assertSame('overpayment', $this->reason(fn () => $this->payments->apply($invoice, new Money(10001, 'ZAR'))));

        $this->assertSame(90000, $invoice->fresh()->amount_paid_cents);
        $this->assertSame(0, PaymentEvent::withoutGlobalScope('team')->count());
        $this->assertSame(0, BillingPayment::withoutGlobalScope('team')->count());
    }

    public function test_a_payment_in_the_wrong_currency_is_rejected(): void
    {
        $invoice = $this->invoice(100000);

        $this->assertSame('currency_mismatch', $this->reason(fn () => $this->payments->apply($invoice, new Money(100000, 'USD'))));
    }

    public function test_zero_and_negative_amounts_are_rejected(): void
    {
        $invoice = $this->invoice();

        $this->assertSame('invalid_amount', $this->reason(fn () => $this->payments->apply($invoice, new Money(0, 'ZAR'))));
        $this->assertSame('invalid_amount', $this->reason(fn () => $this->payments->apply($invoice, new Money(-500, 'ZAR'))));
    }

    public function test_only_an_open_invoice_can_be_paid(): void
    {
        foreach (['draft', 'void', 'paid', 'uncollectible'] as $status) {
            $invoice = $this->invoice(100000, ['status' => $status, 'amount_paid_cents' => $status === 'paid' ? 100000 : 0]);

            $this->assertSame('invoice_not_payable', $this->reason(fn () => $this->payments->apply($invoice, new Money(100, 'ZAR'))), $status);
        }
    }

    public function test_replaying_the_same_provider_event_is_a_no_op(): void
    {
        $invoice = $this->invoice(100000);

        $first = $this->payments->apply($invoice, new Money(40000, 'ZAR'), provider: 'examplepay', externalId: 'evt_9f3a1c77d2b04e55');
        $second = $this->payments->apply($invoice->fresh(), new Money(40000, 'ZAR'), provider: 'examplepay', externalId: 'evt_9f3a1c77d2b04e55');

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->payment->id, $second->payment->id);
        $this->assertSame(40000, $invoice->fresh()->amount_paid_cents, 'applied once, not twice');
        $this->assertSame(1, PaymentEvent::withoutGlobalScope('team')->count());
        $this->assertSame(1, BillingPayment::withoutGlobalScope('team')->count());
    }

    public function test_replaying_after_the_invoice_is_already_paid_is_still_a_clean_no_op(): void
    {
        $invoice = $this->invoice(100000);
        $this->payments->apply($invoice, new Money(100000, 'ZAR'), provider: 'examplepay', externalId: 'evt_full_payment_0001');

        $replay = $this->payments->apply($invoice->fresh(), new Money(100000, 'ZAR'), provider: 'examplepay', externalId: 'evt_full_payment_0001');

        $this->assertTrue($replay->replayed);
        $this->assertSame(100000, $invoice->fresh()->amount_paid_cents);
    }

    public function test_the_same_external_id_with_different_facts_is_a_conflict_not_a_replay(): void
    {
        $invoice = $this->invoice(100000);
        $other = $this->invoice(100000);
        $this->payments->apply($invoice, new Money(40000, 'ZAR'), provider: 'examplepay', externalId: 'evt_conflicting_0002');

        $this->assertSame('payment_conflict', $this->reason(fn () => $this->payments->apply($invoice->fresh(), new Money(50000, 'ZAR'), provider: 'examplepay', externalId: 'evt_conflicting_0002')));
        $this->assertSame('payment_conflict', $this->reason(fn () => $this->payments->apply($other, new Money(40000, 'ZAR'), provider: 'examplepay', externalId: 'evt_conflicting_0002')));
        $this->assertSame(0, $other->fresh()->amount_paid_cents);
    }

    public function test_the_same_external_id_from_two_providers_is_two_different_events(): void
    {
        $invoice = $this->invoice(100000);

        $this->payments->apply($invoice, new Money(10000, 'ZAR'), provider: 'providerone', externalId: 'evt_shared_id_0003');
        $this->payments->apply($invoice->fresh(), new Money(10000, 'ZAR'), provider: 'providertwo', externalId: 'evt_shared_id_0003');

        $this->assertSame(20000, $invoice->fresh()->amount_paid_cents);
    }

    public function test_a_provider_without_an_external_id_or_the_reverse_is_refused(): void
    {
        $invoice = $this->invoice();

        $this->assertSame('invalid_reference', $this->reason(fn () => $this->payments->apply($invoice, new Money(100, 'ZAR'), provider: 'examplepay')));
        $this->assertSame('invalid_reference', $this->reason(fn () => $this->payments->apply($invoice, new Money(100, 'ZAR'), externalId: 'evt_orphan_0004')));
    }

    public function test_events_without_provider_and_external_id_never_collide_with_each_other(): void
    {
        $invoice = $this->invoice(100000);

        $this->payments->apply($invoice, new Money(1000, 'ZAR'));
        $this->payments->apply($invoice->fresh(), new Money(1000, 'ZAR'));

        $this->assertSame(2, PaymentEvent::withoutGlobalScope('team')->count());
    }

    public function test_the_database_enforces_provider_and_external_id_uniqueness(): void
    {
        $invoice = $this->invoice();
        $row = ['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'provider' => 'examplepay', 'external_id' => 'evt_unique_0005', 'type' => 'payment', 'amount_cents' => 1, 'currency' => 'ZAR', 'received_at' => now()];
        PaymentEvent::create($row);

        $this->expectException(UniqueConstraintViolationException::class);
        PaymentEvent::create($row);
    }

    public function test_only_the_payment_type_is_supported_for_now(): void
    {
        $invoice = $this->invoice();

        $this->assertSame('unsupported_type', $this->reason(fn () => $this->payments->apply($invoice, new Money(100, 'ZAR'), type: 'refund')));
    }

    public function test_the_stored_payload_never_keeps_secrets(): void
    {
        $invoice = $this->invoice();

        $result = $this->payments->apply($invoice, new Money(100, 'ZAR'), provider: 'examplepay', externalId: 'evt_payload_0006', payload: [
            'id' => 'evt_payload_0006',
            'api_key' => 'sk_live_must_not_be_stored',
            'headers' => ['Authorization' => 'Bearer must-not-be-stored', 'X-Request-Id' => 'req-1'],
            'data' => ['card_number' => '4242424242424242', 'client_secret' => 'cs_must_not_be_stored', 'note' => 'kept'],
        ]);

        $stored = json_encode($result->event->fresh()->payload);
        $this->assertStringNotContainsString('sk_live_must_not_be_stored', $stored);
        $this->assertStringNotContainsString('must-not-be-stored', $stored);
        $this->assertStringNotContainsString('4242424242424242', $stored);
        $this->assertStringNotContainsString('cs_must_not_be_stored', $stored);
        $this->assertStringContainsString('req-1', $stored);
        $this->assertStringContainsString('kept', $stored);
    }

    public function test_every_payment_leaves_an_audit_row_with_the_actor(): void
    {
        $invoice = $this->invoice(100000);
        $actor = $this->makeOwner();

        $this->payments->apply($invoice, new Money(100000, 'ZAR'), method: 'bank_transfer', payload: ['reference' => 'RTGS-20261105-7781'], actor: $actor);

        $audit = BillingAuditEvent::withoutGlobalScope('team')->where('action', 'payment.recorded')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame(100000, $audit->meta['amount_cents']);
        $this->assertSame('bank_transfer', $audit->meta['method']);
        $this->assertSame('RTGS-20261105-7781', $audit->meta['reference']);
        $this->assertSame(1, BillingAuditEvent::withoutGlobalScope('team')->where('action', 'invoice.paid')->count());
    }

    public function test_paying_in_full_resolves_the_open_dunning_case_for_that_invoice(): void
    {
        $invoice = $this->invoice(100000, ['due_date' => now()->subDays(3)]);
        $case = DunningCase::create(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open']);

        $this->payments->apply($invoice, new Money(100000, 'ZAR'));

        $this->assertSame('resolved', $case->fresh()->status);
        $this->assertNotNull($case->fresh()->resolved_at);
    }

    public function test_a_partial_payment_leaves_the_dunning_case_for_a_human(): void
    {
        $invoice = $this->invoice(100000, ['due_date' => now()->subDays(3)]);
        $case = DunningCase::create(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open']);

        $this->payments->apply($invoice, new Money(1000, 'ZAR'));

        $this->assertSame('open', $case->fresh()->status);
    }

    public function test_a_payment_never_touches_another_teams_invoice(): void
    {
        $mine = $this->invoice(100000);
        $theirs = $this->invoice(100000);

        $this->payments->apply($mine, new Money(100000, 'ZAR'));

        $this->assertSame('open', $theirs->fresh()->status);
        $this->assertSame(0, $theirs->fresh()->amount_paid_cents);
    }
}
