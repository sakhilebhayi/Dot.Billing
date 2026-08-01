<div class="dot-card" style="padding:1.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.75rem;">
        <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0;">Platform Usage</h3>
        <button wire:click="analyzeSpend" class="dot-btn dot-btn-ghost" style="font-size:12px;padding:5px 12px;">
            <span class="material-symbols-rounded" style="font-size:14px;">auto_awesome</span>
            AI Spend Analysis
        </button>
    </div>

    @if(!empty($aiInsights))
    <div style="background:rgba(129,140,248,0.06);border:1px solid rgba(129,140,248,0.2);border-radius:8px;padding:1rem;margin-bottom:1.25rem;">
        <div style="font-size:11px;font-weight:600;color:#818cf8;margin-bottom:0.5rem;text-transform:uppercase;letter-spacing:0.08em;">AI Insights</div>
        @foreach($aiInsights as $insight)
            <div style="font-size:12px;color:#a1a1aa;margin-bottom:4px;">· {{ $insight }}</div>
        @endforeach
    </div>
    @endif

    <div wire:loading.delay wire:target="analyzeSpend" class="dot-loading-overlay">
        <span class="material-symbols-rounded dot-spin" style="font-size:22px;color:#818cf8;">progress_activity</span>
    </div>

    @if(empty($this->usageByPlatform))
        <div style="text-align:center;padding:2rem 0;">
            <span class="material-symbols-rounded" style="font-size:36px;color:#3f3f46;display:block;margin-bottom:0.75rem;">bar_chart</span>
            <p style="font-size:0.8rem;color:#52525b;margin:0;">No usage data recorded yet.</p>
        </div>
    @else
        <div style="display:grid;gap:0.5rem;">
            @foreach($this->usageByPlatform as $platform => $metrics)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:0.65rem 0.75rem;background:rgba(255,255,255,0.03);border-radius:8px;">
                <div style="font-size:12px;font-weight:600;color:#d4d4d8;">{{ $platform }}</div>
                <div style="display:flex;gap:0.75rem;">
                    @foreach($metrics as $m)
                    <span style="font-size:11px;color:#71717a;font-family:'JetBrains Mono',monospace;">{{ $m['metric'] }}: <span style="color:#818cf8;">{{ number_format($m['total'], 0) }}</span></span>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>
