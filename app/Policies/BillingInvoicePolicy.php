<?php

namespace App\Policies;

use App\Models\BillingInvoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Security fix (platform-loop pass, 2026-08-01): prior to this policy, nothing
 * enforced that a user could only view invoices belonging to their own team.
 * The existing Livewire InvoiceTable component happens to scope its query by
 * `currentTeam->id`, but there was no authorization check at all for direct
 * access (e.g. a future `/invoices/{invoice}` route or API endpoint), so any
 * authenticated user could have viewed any team's invoice by ID. This policy
 * closes that gap and is enforced by InvoiceController@show.
 */
class BillingInvoicePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any invoices (scoping happens in the query itself).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the given invoice.
     */
    public function view(User $user, BillingInvoice $invoice): bool
    {
        return $user->belongsToTeam($invoice->team);
    }
}
