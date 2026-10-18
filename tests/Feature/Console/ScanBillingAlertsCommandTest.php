<?php

namespace Tests\Feature\Console;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\DunningCase;
use App\Models\User;
use App\Notifications\InvoiceDueNotification;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ScanBillingAlertsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_overdue_open_invoice_opens_a_case_and_notifies_the_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $invoice = BillingInvoice::create([
            'team_id' => $owner->currentTeam->id,
            'invoice_number' => 'INV-200',
            'status' => 'open',
            'total' => 500,
            'due_date' => now()->subDays(3),
        ]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertDatabaseHas('dunning_cases', [
            'team_id' => $owner->currentTeam->id,
            'invoice_id' => $invoice->id,
            'reason' => 'invoice_overdue',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('billing_alerts', [
            'team_id' => $owner->currentTeam->id,
            'type' => 'invoice_due',
            'status' => 'active',
        ]);
        Notification::assertSentTo($owner, InvoiceDueNotification::class);
    }

    public function test_running_it_twice_does_not_duplicate_the_case_or_renotify(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        BillingInvoice::create([
            'team_id' => $owner->currentTeam->id,
            'invoice_number' => 'INV-201',
            'status' => 'open',
            'total' => 500,
            'due_date' => now()->subDays(3),
        ]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();
        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertDatabaseCount('dunning_cases', 1);
        Notification::assertSentToTimes($owner, InvoiceDueNotification::class, 1);
    }

    public function test_a_failed_payment_opens_a_case_and_notifies_the_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $invoice = BillingInvoice::create([
            'team_id' => $owner->currentTeam->id,
            'invoice_number' => 'INV-202',
            'status' => 'open',
            'total' => 300,
            'due_date' => now()->addDays(10),
        ]);
        $payment = BillingPayment::create([
            'team_id' => $owner->currentTeam->id,
            'invoice_id' => $invoice->id,
            'amount' => 300,
            'status' => 'failed',
            'failure_reason' => 'card_declined',
        ]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertDatabaseHas('dunning_cases', [
            'team_id' => $owner->currentTeam->id,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'reason' => 'payment_failed',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('billing_alerts', [
            'team_id' => $owner->currentTeam->id,
            'type' => 'payment_failed',
        ]);
        Notification::assertSentTo($owner, PaymentFailedNotification::class);
    }

    public function test_a_non_overdue_open_invoice_and_a_succeeded_payment_are_untouched(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        BillingInvoice::create([
            'team_id' => $owner->currentTeam->id,
            'invoice_number' => 'INV-203',
            'status' => 'open',
            'total' => 100,
            'due_date' => now()->addDays(10),
        ]);
        BillingPayment::create([
            'team_id' => $owner->currentTeam->id,
            'amount' => 100,
            'status' => 'succeeded',
        ]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertSame(0, DunningCase::count());
    }

    private function overdueInvoice(array $extra = []): BillingInvoice
    {
        $owner = User::factory()->withPersonalTeam()->create();

        return BillingInvoice::create($extra + [
            'team_id' => $owner->currentTeam->id,
            'invoice_number' => 'SCAN-'.uniqid(),
            'status' => 'open',
            'total_cents' => 50000,
            'due_date' => now()->subDays(3),
        ]);
    }

    public function test_an_invoice_inside_a_grace_period_is_not_flagged_and_gets_no_case(): void
    {
        Notification::fake();
        $invoice = $this->overdueInvoice(['grace_until' => now()->addDays(2)]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertSame(0, DunningCase::count());
        Notification::assertNothingSent();
        $this->assertNotNull($invoice->fresh());
    }

    public function test_a_case_in_grace_is_not_reopened_until_the_grace_has_ended(): void
    {
        Notification::fake();
        $invoice = $this->overdueInvoice(['grace_until' => now()->addDays(2)]);
        $case = DunningCase::create(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'grace', 'grace_until' => now()->addDays(2)]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();
        $this->assertSame('grace', $case->fresh()->status);

        Carbon::setTestNow(now()->addDays(3));
        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertSame('open', $case->fresh()->status, 'grace over and still unpaid: back to a human');
        $this->assertNull($case->fresh()->grace_until);
        $this->assertSame(1, DunningCase::count(), 'reopened, not duplicated');
        Carbon::setTestNow();
    }

    public function test_a_case_in_grace_is_left_alone_when_the_invoice_was_paid_meanwhile(): void
    {
        Notification::fake();
        $invoice = $this->overdueInvoice(['grace_until' => now()->subDay()]);
        $case = DunningCase::create(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'grace', 'grace_until' => now()->subDay()]);
        $invoice->update(['status' => 'paid', 'paid_at' => now(), 'amount_paid_cents' => 50000]);

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertSame('grace', $case->fresh()->status);
    }

    public function test_dismissed_and_cancelled_cases_are_never_reopened(): void
    {
        Notification::fake();
        $dismissed = $this->overdueInvoice();
        $canceled = $this->overdueInvoice();
        DunningCase::create(['team_id' => $dismissed->team_id, 'invoice_id' => $dismissed->id, 'reason' => 'invoice_overdue', 'status' => 'dismissed']);
        DunningCase::create(['team_id' => $canceled->team_id, 'invoice_id' => $canceled->id, 'reason' => 'invoice_overdue', 'status' => 'canceled']);

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertSame(['canceled', 'dismissed'], DunningCase::orderBy('status')->pluck('status')->all());
    }

    public function test_the_database_refuses_a_second_overdue_case_for_one_invoice_and_a_second_case_for_one_payment(): void
    {
        $invoice = $this->overdueInvoice();
        $payment = BillingPayment::create(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'amount_cents' => 100, 'currency' => 'ZAR', 'status' => 'failed']);
        DunningCase::create(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open']);
        DunningCase::create(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'reason' => 'payment_failed', 'status' => 'open']);

        try {
            // A savepoint, so a refused insert does not poison the surrounding test transaction on PostgreSQL.
            DB::transaction(fn () => DunningCase::create(['team_id' => $invoice->team_id, 'payment_id' => $payment->id, 'reason' => 'payment_failed', 'status' => 'open']));
            $this->fail('a second case for one payment should be refused');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }

        if (in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            $this->expectException(UniqueConstraintViolationException::class);
            DunningCase::create(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open']);
        }
    }

    public function test_a_failed_payment_and_an_overdue_invoice_can_each_have_their_own_case(): void
    {
        Notification::fake();
        $invoice = $this->overdueInvoice();
        BillingPayment::create(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'amount_cents' => 100, 'currency' => 'ZAR', 'status' => 'failed']);

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertSame(['invoice_overdue', 'payment_failed'], DunningCase::orderBy('reason')->pluck('reason')->all());
    }

    public function test_the_scanner_survives_losing_a_race_to_another_scanner(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            $this->markTestSkipped('The unique guard on overdue cases is a partial index: sqlite and pgsql only.');
        }

        Notification::fake();
        $invoice = $this->overdueInvoice();

        // Another scanner commits its case right after this scan's existence check and before its insert.
        $raced = false;
        DB::listen(function ($query) use ($invoice, &$raced) {
            if (! $raced && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'dunning_cases') && str_contains($query->sql, 'reason')) {
                $raced = true;
                DB::table('dunning_cases')->insert(['team_id' => $invoice->team_id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->artisan('billing:scan-alerts')->assertSuccessful();

        $this->assertSame(1, DunningCase::where('invoice_id', $invoice->id)->where('reason', 'invoice_overdue')->count());
    }
}
