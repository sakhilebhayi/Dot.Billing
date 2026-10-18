<?php

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionLifecycle;
use Illuminate\Console\Command;

class AdvanceSubscriptions extends Command
{
    protected $signature = 'billing:advance-subscriptions';

    protected $description = 'End expired trials, roll ended billing periods forward (invoicing the renewal), end subscriptions scheduled to cancel, and keep past_due accurate. Idempotent; never charges and never cancels for non-payment.';

    public function handle(SubscriptionLifecycle $lifecycle): int
    {
        $stats = $lifecycle->run();

        $this->info(sprintf(
            'Advanced %d period(s), ended %d, marked %d past due, recovered %d, %d failed.',
            $stats['advanced'], $stats['ended'], $stats['past_due'], $stats['recovered'], $stats['failed']
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
