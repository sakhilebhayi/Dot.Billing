<?php

namespace App\Notifications;

use App\Models\BillingInvoice;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification for an upcoming/overdue invoice.
 * Not yet wired to any automatic trigger — dispatch manually via
 * `$user->notify(new InvoiceDueNotification($invoice))` until subscription/
 * invoice lifecycle events exist (see wiki.md §5 and §8).
 */
class InvoiceDueNotification extends Notification
{
    public function __construct(public BillingInvoice $invoice) {}

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
            'type' => 'invoice_due',
            'title' => 'Invoice due',
            'message' => "Invoice {$this->invoice->invoice_number} for {$this->invoice->currency} ".number_format((float) $this->invoice->total, 2).' is due '.($this->invoice->due_date?->format('M d, Y') ?? 'soon').'.',
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'url' => route('invoices.show', $this->invoice),
        ];
    }
}
