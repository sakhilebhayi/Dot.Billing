<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    /**
     * Show a single invoice. Authorization is delegated to BillingInvoicePolicy,
     * which restricts access to members of the invoice's own team.
     */
    public function show(BillingInvoice $invoice): View
    {
        Gate::authorize('view', $invoice);

        $invoice->load('items');

        return view('invoices.show', [
            'invoice' => $invoice,
        ]);
    }
}
