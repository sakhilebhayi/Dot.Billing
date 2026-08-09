<?php

namespace Tests\Feature\Console;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\DunningCase;
use App\Models\User;
use App\Notifications\InvoiceDueNotification;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
