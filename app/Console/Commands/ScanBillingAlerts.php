<?php

namespace App\Console\Commands;

use App\Models\BillingAlert;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\DunningCase;
use App\Notifications\InvoiceDueNotification;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Console\Command;

class ScanBillingAlerts extends Command
{
    protected $signature = 'billing:scan-alerts';

    protected $description = 'Raise alerts for overdue invoices and failed payments, and open a dunning case for a platform operator to review; never suspends or cancels anything on its own.';

    public function handle(): int
    {
        $opened = 0;

        foreach (BillingInvoice::where('status', 'open')->get() as $invoice) {
            try {
                if ($invoice->isOverdue() && ! DunningCase::where('invoice_id', $invoice->id)->exists()) {
                    $this->openCase($invoice->team_id, invoiceId: $invoice->id, paymentId: null, reason: 'invoice_overdue');
                    $this->ensureAlert($invoice->team_id, 'invoice_due');
                    $invoice->team->owner?->notify(new InvoiceDueNotification($invoice));
                    $opened++;
                }
            } catch (\Throwable $e) {
                $this->error("Failed to process invoice #{$invoice->id}: {$e->getMessage()}");
            }
        }

        foreach (BillingPayment::where('status', 'failed')->get() as $payment) {
            try {
                if (! DunningCase::where('payment_id', $payment->id)->exists()) {
                    $this->openCase($payment->team_id, invoiceId: $payment->invoice_id, paymentId: $payment->id, reason: 'payment_failed');
                    $this->ensureAlert($payment->team_id, 'payment_failed');
                    $payment->team->owner?->notify(new PaymentFailedNotification($payment));
                    $opened++;
                }
            } catch (\Throwable $e) {
                $this->error("Failed to process payment #{$payment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Opened {$opened} dunning case(s).");

        return self::SUCCESS;
    }

    private function openCase(int $teamId, ?int $invoiceId, ?int $paymentId, string $reason): void
    {
        DunningCase::create([
            'team_id' => $teamId,
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'reason' => $reason,
            'status' => 'open',
        ]);
    }

    private function ensureAlert(int $teamId, string $type): void
    {
        $exists = BillingAlert::where('team_id', $teamId)
            ->where('type', $type)
            ->where('status', 'active')
            ->exists();

        if (! $exists) {
            BillingAlert::create([
                'team_id' => $teamId,
                'type' => $type,
                'status' => 'active',
                'triggered_at' => now(),
            ]);
        }
    }
}
