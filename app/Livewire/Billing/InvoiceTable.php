<?php

namespace App\Livewire\Billing;

use App\Models\BillingInvoice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class InvoiceTable extends Component
{
    use WithPagination;

    public string $filterStatus = '';

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function canSeeDrafts(): bool
    {
        $team = Auth::user()?->currentTeam;

        return $team !== null && Gate::allows('manage-billing', $team);
    }

    #[Computed]
    public function invoices()
    {
        // team scoping comes from BillingInvoice's HasTeamScope global scope
        return BillingInvoice::query()
            // A draft is internal until finalized; only managers see it.
            ->unless($this->canSeeDrafts, fn ($q) => $q->where('status', '!=', 'draft'))
            ->when($this->filterStatus === 'overdue', fn ($q) => $q->overdue())
            ->when($this->filterStatus && $this->filterStatus !== 'overdue', fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->search, fn ($q) => $q->where('invoice_number', 'like', '%'.$this->search.'%'))
            ->orderByDesc('id')
            ->paginate(10);
    }

    public function render()
    {
        return view('livewire.billing.invoice-table');
    }
}
