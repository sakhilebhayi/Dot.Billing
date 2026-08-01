<?php

namespace App\Livewire\Billing;

use App\Models\BillingInvoice;
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
    public function invoices()
    {
        return BillingInvoice::where('team_id', auth()->user()->currentTeam->id)
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->search, fn ($q) => $q->where('invoice_number', 'like', '%' . $this->search . '%'))
            ->orderByDesc('created_at')
            ->paginate(10);
    }

    public function render()
    {
        return view('livewire.billing.invoice-table');
    }
}
