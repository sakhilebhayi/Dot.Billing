<div style="position:relative;" x-data @click.outside="$wire.open = false">
    <button wire:click="toggle" class="topbar-btn" title="Notifications" style="position:relative;">
        <span class="material-symbols-rounded">notifications</span>
        @if($this->unreadCount > 0)
            <span style="position:absolute;top:-2px;right:-2px;min-width:15px;height:15px;padding:0 3px;border-radius:100px;background:#ef4444;color:#fff;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;line-height:1;">
                {{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}
            </span>
        @endif
    </button>

    @if($open)
    <div style="position:absolute;top:calc(100% + 8px);right:0;width:320px;max-height:420px;overflow-y:auto;background:#141416;border:1px solid rgba(255,255,255,0.09);border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,0.45);z-index:50;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:0.85rem 1rem;border-bottom:1px solid rgba(255,255,255,0.06);">
            <span style="font-family:'Syne',sans-serif;font-size:12.5px;font-weight:700;color:#f4f4f5;">Notifications</span>
            @if($this->unreadCount > 0)
                <button wire:click="markAllAsRead" style="background:none;border:none;color:#818cf8;font-size:11px;font-weight:600;cursor:pointer;padding:0;">Mark all read</button>
            @endif
        </div>

        @if($this->notifications->isEmpty())
            <div style="text-align:center;padding:2rem 1rem;">
                <span class="material-symbols-rounded" style="font-size:30px;color:#3f3f46;display:block;margin-bottom:0.5rem;">notifications_off</span>
                <p style="font-size:0.75rem;color:#52525b;margin:0;">No notifications yet.</p>
            </div>
        @else
            <div>
                @foreach($this->notifications as $notification)
                <div wire:click="markAsRead('{{ $notification->id }}')" style="padding:0.7rem 1rem;border-bottom:1px solid rgba(255,255,255,0.05);cursor:pointer;{{ $notification->read_at ? 'opacity:0.55;' : '' }}">
                    <div style="display:flex;align-items:center;gap:6px;">
                        @if(!$notification->read_at)
                            <span style="width:6px;height:6px;border-radius:50%;background:#818cf8;flex-shrink:0;"></span>
                        @endif
                        <span style="font-size:12px;font-weight:600;color:#d4d4d8;">{{ $notification->data['title'] ?? 'Notification' }}</span>
                    </div>
                    <div style="font-size:11px;color:#71717a;margin-top:3px;line-height:1.4;">{{ $notification->data['message'] ?? '' }}</div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
    @endif
</div>
