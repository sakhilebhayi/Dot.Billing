<?php

namespace App\Console\Commands;

use App\Models\BillingSubscription;
use App\Services\Billing\InvoiceGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateInvoices extends Command
{
    protected $signature = 'billing:generate-invoices';

    protected $description = 'Create the invoice for every live paid subscription whose current billing period has none yet. Idempotent. Run after billing:advance-subscriptions, which rolls ended periods forward.';

    public function handle(InvoiceGenerator $generator): int
    {
        $created = 0;
        $failed = 0;

        $candidates = BillingSubscription::withoutGlobalScope('team')
            ->with('plan')
            ->whereIn('status', ['active', 'past_due'])
            ->whereNotNull('current_period_start')
            ->where('current_period_start', '<=', now())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('billing_invoices')
                ->whereColumn('billing_invoices.subscription_id', 'billing_subscriptions.id')
                ->whereColumn('billing_invoices.period_start', 'billing_subscriptions.current_period_start'))
            ->cursor();

        foreach ($candidates as $subscription) {
            try {
                if ($generator->generateForCurrentPeriod($subscription)) {
                    $created++;
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error('Invoice generation failed.', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);
                $this->error("Subscription #{$subscription->id}: {$e->getMessage()}");
            }
        }

        $this->info("Created {$created} invoice(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
