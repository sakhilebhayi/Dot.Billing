<div class="dot-card" style="padding:1.5rem;position:relative;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;flex-wrap:wrap;gap:0.5rem;">
        <h3 class="dot-h">Invoices</h3>
        <div style="display:flex;gap:0.5rem;">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Search invoice #..."
                aria-label="Search invoice number"
                class="dot-input"
                style="font-size:11px;padding:4px 8px;width:150px;"
            />
            <select wire:model.live="filterStatus" aria-label="Filter by status" class="dot-input" style="font-size:11px;padding:4px 8px;width:auto;">
                <option value="">All</option>
                @if($this->canSeeDrafts)<option value="draft">Draft</option>@endif
                <option value="open">Open</option>
                <option value="overdue">Overdue</option>
                <option value="paid">Paid</option>
                <option value="void">Void</option>
            </select>
        </div>
    </div>

    <div wire:loading.delay class="dot-loading-overlay">
        <span class="material-symbols-rounded dot-spin" style="font-size:22px;color:#818cf8;">progress_activity</span>
    </div>

    <div wire:loading.remove.delay>
        @if($this->invoices->isEmpty())
            <div style="text-align:center;padding:2rem 0;">
                <span class="material-symbols-rounded" style="font-size:36px;color:#3f3f46;display:block;margin-bottom:0.75rem;">receipt</span>
                <p style="font-size:0.8rem;color:#71717a;margin:0;">
                    {{ $search || $filterStatus ? 'No invoices match your filters.' : 'No invoices yet. They appear here when a subscription is billed.' }}
                </p>
            </div>
        @else
            <div class="table-scroll">
                <table class="dot-table">
                    <thead>
                        <tr><th>Invoice</th><th>Issued</th><th>Due</th><th class="num">Total</th><th class="num">Balance</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    @foreach($this->invoices as $invoice)
                        <tr wire:key="invoice-{{ $invoice->id }}">
                            <td><a class="row-link" href="{{ route('invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a></td>
                            <td class="dot-muted">{{ ($invoice->issued_at ?? $invoice->created_at)->format('j M Y') }}</td>
                            <td class="dot-muted">{{ $invoice->due_date?->format('j M Y') ?? '—' }}</td>
                            <td class="num">{{ $invoice->totalMoney()->format() }}</td>
                            <td class="num">{{ in_array($invoice->status, ['open']) ? $invoice->balanceMoney()->format() : '—' }}</td>
                            <td><x-billing.invoice-status :invoice="$invoice" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top:1rem;">{{ $this->invoices->links() }}</div>
        @endif
    </div>
</div>
