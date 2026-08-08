<div class="dot-card" style="padding:1.5rem;">
    <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 1.25rem;">Payment Reliability</h3>
    <div wire:loading.delay class="dot-loading-overlay">
        <span class="material-symbols-rounded dot-spin" style="font-size:22px;color:#818cf8;">progress_activity</span>
    </div>
    <div wire:loading.remove.delay>
    @if($this->cushion['available'])
        <div class="metric-val" style="font-family:'Syne',sans-serif;font-size:1.65rem;font-weight:700;color:#22c55e;">
            {{ $this->cushion['on_time_rate_pct'] }}%
        </div>
        <div style="font-size:11px;color:#71717a;margin-top:6px;">
            {{ $this->cushion['basis'] }}
        </div>
        @if($this->cushion['what_if'])
            <div style="font-size:11px;color:#a1a1aa;margin-top:8px;">
                {{ $this->cushion['what_if']['overdue_count'] }} invoice(s) currently overdue. Resolving them would bring the rate to approximately {{ $this->cushion['what_if']['projected_resolved_rate_pct'] }}%.
            </div>
        @endif
    @else
        <div style="text-align:center;padding:1rem 0;">
            <span class="material-symbols-rounded" style="font-size:32px;color:#3f3f46;display:block;margin-bottom:0.5rem;">receipt_long</span>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">Not enough invoice history yet to compute this.</p>
        </div>
    @endif
    </div>
</div>
