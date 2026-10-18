<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(): View
    {
        return view('invoices.index');
    }

    /**
     * Show a single invoice. Authorization is delegated to BillingInvoicePolicy:
     * members of the invoice's own team, and drafts only for those who manage billing.
     */
    public function show(BillingInvoice $invoice): View
    {
        Gate::authorize('view', $invoice);

        $invoice->load(['items', 'team']);

        return view('invoices.show', [
            'invoice' => $invoice,
            'canManage' => Gate::allows('manage-billing', $invoice->team),
        ]);
    }

    /**
     * A standalone, print-friendly rendering of the same invoice: no app chrome, black on white.
     */
    public function print(BillingInvoice $invoice): View
    {
        Gate::authorize('view', $invoice);

        $invoice->load(['items', 'team']);

        return view('invoices.print', ['invoice' => $invoice, 'issuer' => config('billing.issuer')]);
    }
}
