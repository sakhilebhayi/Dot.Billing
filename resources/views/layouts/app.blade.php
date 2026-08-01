<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dot.Billing</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class', corePlugins: { preflight: false } }</script>
    <script>
        // Dark mode: this dashboard's own markup is intentionally fixed-dark (inline styles,
        // not Tailwind dark: classes), so this toggle mainly affects Jetstream-rendered pages
        // (Profile, Team Settings) which do use dark: classes. Kept for theme consistency
        // ecosystem-wide and so those subpages respect the user's light/dark preference.
        (function () {
            const stored = localStorage.getItem('dot-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <style>
        :root { --accent: #818cf8; --accent-rgb: 129,140,248; }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin:0; background:#09090b; color:#f4f4f5; font-family:'Inter',system-ui,sans-serif; font-size:14px; line-height:1.5; }
        .material-symbols-rounded { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; line-height:1; user-select:none; }
        [x-cloak] { display:none!important; }
        .sidebar { position:fixed; left:0; top:0; width:260px; height:100vh; background:#0d0d10; border-right:1px solid rgba(255,255,255,0.06); display:flex; flex-direction:column; z-index:40; overflow:hidden; }
        .sidebar::before { content:''; position:absolute; top:-80px; left:-80px; width:320px; height:320px; background:radial-gradient(circle, rgba(129,140,248,0.1) 0%, transparent 65%); pointer-events:none; }
        .sidebar-brand { padding:20px 18px 14px; display:flex; align-items:center; gap:11px; flex-shrink:0; }
        .brand-icon { width:36px; height:36px; border-radius:10px; background:rgba(129,140,248,0.12); border:1px solid rgba(129,140,248,0.22); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .brand-icon .material-symbols-rounded { font-size:18px; color:#818cf8; }
        .brand-name { font-family:'Syne',sans-serif; font-size:14.5px; font-weight:700; color:#f4f4f5; letter-spacing:-0.01em; line-height:1.2; }
        .brand-status { display:flex; align-items:center; gap:5px; margin-top:3px; }
        .live-dot { width:6px; height:6px; border-radius:50%; background:#818cf8; flex-shrink:0; animation:live-pulse 2.8s ease-in-out infinite; }
        @keyframes live-pulse { 0%,100% { opacity:1; box-shadow:0 0 0 0 rgba(129,140,248,0.45); } 60% { opacity:.6; box-shadow:0 0 0 5px rgba(129,140,248,0); } }
        .brand-subtitle { font-size:10px; font-weight:500; color:#3f3f46; text-transform:uppercase; letter-spacing:0.09em; }
        .sidebar-divider { height:1px; background:rgba(255,255,255,0.06); margin:4px 14px 8px; }
        .sidebar-nav { padding:0 10px; flex:1; overflow-y:auto; scrollbar-width:none; }
        .sidebar-nav::-webkit-scrollbar { display:none; }
        .nav-section-label { font-size:10px; font-weight:600; color:#3f3f46; text-transform:uppercase; letter-spacing:0.1em; padding:14px 8px 5px; }
        .nav-item { display:flex; align-items:center; gap:9px; padding:7.5px 10px; border-radius:8px; font-size:13px; font-weight:500; color:#71717a; text-decoration:none; transition:background .13s,color .13s,transform .13s; margin-bottom:1px; }
        .nav-item:hover { background:rgba(255,255,255,0.05); color:#d4d4d8; transform:translateX(1px); }
        .nav-item.active { background:rgba(129,140,248,0.1); color:#818cf8; font-weight:600; }
        .nav-icon { font-size:17px; width:20px; text-align:center; flex-shrink:0; }
        .sidebar-footer { padding:10px 14px 14px; border-top:1px solid rgba(255,255,255,0.06); flex-shrink:0; }
        .user-row { display:flex; align-items:center; gap:9px; padding:8px 6px; border-radius:8px; }
        .user-avatar { width:28px; height:28px; border-radius:50%; background:rgba(129,140,248,0.18); border:1px solid rgba(129,140,248,0.28); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:#818cf8; flex-shrink:0; font-family:'Syne',sans-serif; }
        .user-name { font-size:12px; font-weight:600; color:#d4d4d8; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .user-team { font-size:10px; color:#52525b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .topbar { position:fixed; top:0; left:260px; right:0; height:54px; background:rgba(9,9,11,0.85); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; padding:0 22px; z-index:30; gap:12px; }
        .topbar-title { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; color:#f4f4f5; flex:1; }
        .topbar-team { font-size:11px; color:#52525b; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.07); border-radius:6px; padding:3px 8px; font-weight:500; white-space:nowrap; }
        .topbar-btn { width:30px; height:30px; border-radius:7px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.04); display:flex; align-items:center; justify-content:center; color:#71717a; cursor:pointer; transition:background .13s,color .13s; text-decoration:none; flex-shrink:0; }
        .topbar-btn:hover { background:rgba(255,255,255,0.09); color:#d4d4d8; }
        .topbar-btn .material-symbols-rounded { font-size:17px; }
        .content-wrap { margin-left:260px; padding-top:54px; min-height:100vh; }
        .dot-card { background:#141416; border:1px solid rgba(255,255,255,0.07); border-radius:12px; }
        .dot-card:hover { border-color:rgba(255,255,255,0.11); }
        .metric-val { font-family:'JetBrains Mono',monospace; font-weight:500; letter-spacing:-0.02em; }
        .dot-input { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:8px; color:#f4f4f5; font-family:'Inter',sans-serif; font-size:13px; padding:8px 12px; width:100%; transition:border-color .15s,box-shadow .15s; outline:none; }
        .dot-input:focus { border-color:rgba(129,140,248,0.45); box-shadow:0 0 0 3px rgba(129,140,248,0.07); }
        .dot-input::placeholder { color:#3f3f46; }
        .dot-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:all .14s; border:none; text-decoration:none; font-family:'Inter',sans-serif; }
        .dot-btn-primary { background:#818cf8; color:#09090b; }
        .dot-btn-primary:hover { filter:brightness(1.1); }
        .dot-btn-ghost { background:rgba(255,255,255,0.06); color:#a1a1aa; border:1px solid rgba(255,255,255,0.08); }
        .dot-btn-ghost:hover { background:rgba(255,255,255,0.1); color:#f4f4f5; }
        .dot-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:100px; font-size:11px; font-weight:600; }
        .dot-badge-accent { background:rgba(129,140,248,0.12); color:#818cf8; }
        select.dot-input option { background:#1a1a1f; }
        .dot-loading-overlay { display:flex; align-items:center; justify-content:center; padding:2.5rem 0; }
        .dot-spin { animation: dot-spin 0.9s linear infinite; }
        @keyframes dot-spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
    </style>
    @livewireStyles
    <script defer src="https://unpkg.com/alpinejs@3.10.2/dist/cdn.min.js"></script>
</head>
<body>
    <x-banner />

    <aside class="sidebar">
        <div class="sidebar-brand">
            <!-- TODO(branding): placeholder Dot.Billing mark — replace with the official ecosystem-issued logo once one exists (see wiki.md §8 roadmap). -->
            <div class="brand-icon">
                <svg width="20" height="20" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="24" cy="24" r="24" fill="#818cf8"/>
                    <text x="24" y="32" font-family="Georgia, 'Times New Roman', serif" font-size="24" font-weight="700" fill="#09090b" text-anchor="middle">B</text>
                </svg>
            </div>
            <div>
                <div class="brand-name">Dot.Billing</div>
                <div class="brand-status">
                    <div class="live-dot"></div>
                    <span class="brand-subtitle">Billing Intelligence</span>
                </div>
            </div>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">dashboard</span>
                Dashboard
            </a>
            <div class="sidebar-divider" style="margin:10px 0;"></div>
            <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">manage_accounts</span>
                Profile & Settings
            </a>
        </nav>

        @auth
        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                <div style="min-width:0;flex:1;">
                    <div class="user-name">{{ Auth::user()->name }}</div>
                    <div class="user-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</div>
                </div>
            </div>
        </div>
        @endauth
    </aside>

    <header class="topbar">
        <div class="topbar-title">
            @isset($header){{ $header }}
            @else
            Dot.Billing
            @endisset
        </div>
        @auth
        <span class="topbar-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</span>
        <livewire:notification-bell />
        @endauth
        <button
            type="button"
            onclick="document.documentElement.classList.toggle('dark'); localStorage.setItem('dot-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');"
            class="topbar-btn"
            title="Toggle dark mode"
        >
            <span class="material-symbols-rounded">dark_mode</span>
        </button>
        <a href="{{ route('profile.show') }}" class="topbar-btn" title="Profile">
            <span class="material-symbols-rounded">account_circle</span>
        </a>
    </header>

    @livewire('navigation-menu')

    <div class="content-wrap">
        <main>{{ $slot }}</main>
    </div>

    @stack('modals')
    @livewireScripts
</body>
</html>
