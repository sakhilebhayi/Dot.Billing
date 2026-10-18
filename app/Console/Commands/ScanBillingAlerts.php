<?php

namespace App\Console\Commands;

use App\Models\BillingAlert;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\DunningCase;
use App\Notifications\InvoiceDueNotification;
use App\Notifications\PaymentFailedNotification;
use App\Services\Billing\AuditLog;
use App\Services\Notify\NotifyClient;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class ScanBillingAlerts extends Command
{
    protected $signature = 'billing:scan-alerts';

    protected $description = 'Raise alerts for overdue invoices and failed payments, and open a dunning case for a platform operator to review. Reopens a case only after its grace period has ended. Never suspends or cancels anything on its own.';

    public function handle(AuditLog $audit, NotifyClient $notify): int
    {
        $opened = 0;
        $reopened = 0;

        // Overdue already excludes an invoice inside a grace period a person granted.
        foreach (BillingInvoice::withoutGlobalScope('team')->overdue()->with('team.owner')->cursor() as $invoice) {
            try {
                $case = DunningCase::where('invoice_id', $invoice->id)->where('reason', 'invoice_overdue')->first();

                if ($case === null) {
                    $case = $this->openCase($invoice->team_id, invoiceId: $invoice->id, paymentId: null, reason: 'invoice_overdue');

                    if ($case === null) {
                        continue; // another scanner won the race
                    }

                    $audit->record('dunning.opened', $invoice->team_id, null, $invoice, ['case_id' => $case->id, 'reason' => 'invoice_overdue']);
                    $opened++;
                } elseif ($case->status === 'grace' && $case->grace_until !== null && $case->grace_until->lte(now())) {
                    $case->forceFill(['status' => 'open', 'grace_until' => null, 'resolved_at' => null, 'resolved_by' => null])->save();
                    $audit->record('dunning.reopened', $invoice->team_id, null, $invoice, ['case_id' => $case->id, 'reason' => 'grace period ended, invoice still unpaid']);
                    $reopened++;
                } else {
                    continue; // open, or decided by a person: dismissed, canceled, resolved
                }

                $this->ensureAlert($invoice->team_id, 'invoice_due');
                $invoice->team->owner?->notify(new InvoiceDueNotification($invoice));
                $notify->invoiceOverdue($invoice);
            } catch (Throwable $e) {
                $this->error("Failed to process invoice #{$invoice->id}: {$e->getMessage()}");
            }
        }

        foreach (BillingPayment::withoutGlobalScope('team')->where('status', 'failed')->with('team.owner')->cursor() as $payment) {
            try {
                if (DunningCase::where('payment_id', $payment->id)->exists()) {
                    continue;
                }

                if ($this->openCase($payment->team_id, invoiceId: $payment->invoice_id, paymentId: $payment->id, reason: 'payment_failed') === null) {
                    continue;
                }

                $this->ensureAlert($payment->team_id, 'payment_failed');
                $payment->team->owner?->notify(new PaymentFailedNotification($payment));
                $opened++;
            } catch (Throwable $e) {
                $this->error("Failed to process payment #{$payment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Opened {$opened} dunning case(s), reopened {$reopened}.");

        return self::SUCCESS;
    }

    /**
     * Creates the case, or returns null if the database says one already exists (a concurrent scan).
     */
    private function openCase(int $teamId, ?int $invoiceId, ?int $paymentId, string $reason): ?DunningCase
    {
        try {
            return DB::transaction(fn () => DunningCase::create([
                'team_id' => $teamId,
                'invoice_id' => $invoiceId,
                'payment_id' => $paymentId,
                'reason' => $reason,
                'status' => 'open',
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function ensureAlert(int $teamId, string $type): void
    {
        $exists = BillingAlert::withoutGlobalScope('team')->where('team_id', $teamId)
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
