<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.4rem;letter-spacing:-0.01em;">Entitlement lookup</h1>
    <p class="dot-muted" style="margin:0 0 1.5rem;max-width:46rem;">Shows what <span style="font-family:'JetBrains Mono',monospace;">GET /api/entitlements/{company_uuid}</span> returns for a company id, as the other Dot apps see it. Read only.</p>

    <form method="GET" action="{{ route('operator.entitlements.index') }}" class="btn-row" style="margin-bottom:1.25rem;max-width:46rem;">
        <label for="company" class="dot-label" style="flex-basis:100%;">InfoDot company id</label>
        <input id="company" name="company" type="text" value="{{ $company }}" class="dot-input" style="flex:1;min-width:18rem;font-family:'JetBrains Mono',monospace;" placeholder="00000000-0000-4000-8000-000000000000" autocomplete="off" spellcheck="false">
        <button type="submit" class="dot-btn dot-btn-primary">Look up</button>
    </form>

    @if($searched && ! $team)
        <div class="state-line attn" role="status"><span class="dot" aria-hidden="true"></span><span>No team here is linked to that company id.</span></div>
    @endif

    @if($team)
        <div class="dot-card" style="padding:0.5rem 1.5rem;margin-bottom:1.25rem;">
            <div class="readout">
                <div class="k">Team</div><div class="v" style="font-family:'Inter',sans-serif;">{{ $team->name }}</div>
                <div class="k">Company id</div><div class="v">{{ $entitlement['company_uuid'] }}</div>
                <div class="k">Status</div><div class="v">{{ $entitlement['status'] }}@if($entitlement['cancel_at_period_end']) <span style="font-family:'Inter',sans-serif;color:#f59e0b;">cancels at period end</span>@endif</div>
                <div class="k">Plan</div><div class="v">{{ $entitlement['plan']['slug'] ?? 'none (free tier)' }}</div>
                <div class="k">Seats</div><div class="v">{{ $entitlement['seat_limit'] ?? 'unlimited' }}</div>
                <div class="k">Storage</div><div class="v">{{ $entitlement['storage_gb'] === null ? 'unlimited' : $entitlement['storage_gb'].' GB' }}</div>
                <div class="k">Features</div><div class="v">@forelse($entitlement['features'] as $feature)<span>{{ $feature }}</span>@empty<span class="none">none</span>@endforelse</div>
                <div class="k">Period end</div><div class="v">{{ $entitlement['period_end'] ?? '—' }}</div>
            </div>
        </div>

        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div class="dot-label" style="margin-bottom:8px;">Response body</div>
            <pre style="margin:0;font-family:'JetBrains Mono',monospace;font-size:12px;color:#d4d4d8;overflow-x:auto;">{{ json_encode($entitlement, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif
</div>
</x-app-layout>
