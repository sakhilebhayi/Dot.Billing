<?php

namespace App\Notifications;

use App\Models\BillingPayment;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification for a failed payment attempt.
 * Not yet wired to any automatic trigger — dispatch manually via
 * `$user->notify(new PaymentFailedNotification($payment))` until payment
 * lifecycle events exist (see wiki.md §5 and §8).
 */
class PaymentFailedNotification extends Notification
{
    public function __construct(public BillingPayment $payment)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type'      => 'payment_failed',
            'title'     => 'Payment failed',
            'message'   => 'A payment of ' . $this->payment->currency . ' ' . number_format((float) $this->payment->amount, 2) . ' failed' . ($this->payment->failure_reason ? ": {$this->payment->failure_reason}" : '.'),
            'payment_id' => $this->payment->id,
        ];
    }
}
