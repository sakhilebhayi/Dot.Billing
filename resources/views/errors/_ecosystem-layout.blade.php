<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $code }} — @yield('title') · Dot.Billing</title>
        <meta name="robots" content="noindex">

        @php
            $faviconPath = null;
            foreach (['favicon.ico', 'favicon-32x32.png', 'favicon-16x16.png'] as $faviconCandidate) {
                if (file_exists(public_path($faviconCandidate))) {
                    $faviconPath = $faviconCandidate;
                    break;
                }
            }
        @endphp
        @if ($faviconPath)
            <link rel="icon" href="{{ asset($faviconPath) }}">
        @endif

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">

        @php
            $viteManifestPath = public_path('build/manifest.json');
            $viteEntries = [];
            if (file_exists($viteManifestPath)) {
                $viteManifest = json_decode(file_get_contents($viteManifestPath), true) ?? [];
                foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
                    if (isset($viteManifest[$entry])) {
                        $viteEntries[] = $entry;
                    }
                }
            }

            // Every Dot platform, pulled from the shared ecosystem registry
            // (config/ecosystem.php, identical across all platforms) rather
            // than a fixed hand-picked subset -- add a platform to the
            // registry once and it shows up here automatically everywhere.
            // Self-exclusion uses this generator-verified literal name
            // rather than config('app.name'), since not every platform's
            // .env reliably has APP_NAME set correctly.
            $currentPlatformName = 'Dot.Billing';
            $discover = collect(config('ecosystem.platforms', []))
                ->reject(fn ($p) => ($p['name'] ?? null) === $currentPlatformName)
                ->reject(fn ($p) => ($p['active'] ?? true) === false)
                ->values()
                ->all();

            $logoLightPath = null;
            foreach (['images/logo-light.png', null] as $logoLightCandidate) {
                if ($logoLightCandidate && file_exists(public_path($logoLightCandidate))) {
                    $logoLightPath = $logoLightCandidate;
                    break;
                }
            }
        @endphp
        @if (!empty($viteEntries))
            @vite($viteEntries)
        @endif

        <style>
            :root {
                --paper: #faf6ec;
                --ink: #1e2019;
                --ink-soft: #585c4e;
                --chrome: #1e2019;
                --chrome-soft: #1e2019;
                --accent: #f1c62e;
                --accent-deep: #b8860f;
                --line: rgba(0,0,0,0.12);
                --font-display: 'Fraunces', Georgia, serif;
                --font-body: 'IBM Plex Sans', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            * { box-sizing: border-box; }
            html { background: var(--paper); }
            body { margin: 0; font-family: var(--font-body); background: var(--paper); color: #585c4e; min-height: 100dvh; display: flex; flex-direction: column; }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }
            a { color: inherit; }
            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }
            .link-underline { background-image: linear-gradient(currentColor, currentColor); background-position: 0 100%; background-repeat: no-repeat; background-size: 0% 1px; transition: background-size 200ms var(--ease-out); }
            @media (hover: hover) and (pointer: fine) {
                .link-underline:hover { background-size: 100% 1px; }
            }
        </style>
    </head>
    <body>
        <header style="background: var(--chrome);">
            <nav style="max-width: 1400px; margin: 0 auto; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between;">
                <a href="/" class="press" style="display: flex; align-items: center; gap: 10px;">
                    {{-- The header sits on --chrome, which is always a dark
                         surface in this template regardless of whether the
                         platform's overall theme is light or dark -- so the
                         logo's ink-colored wordmark needs the dark-safe
                         white-ink variant here, not the default asset. --}}
                    <img src="{{ asset($logoLightPath ?? 'images/logo.png') }}" alt="Dot.Billing" style="height: 40px; width: auto;">
                </a>
                <div style="display: flex; align-items: center; gap: 12px;">
                    @auth
                        <a href="{{ route('dashboard') }}" class="press" style="padding: 10px 20px; background: var(--accent); color: #1e2019; font-family: var(--font-display); font-weight: 600; font-size: 14px; border-radius: 999px; text-decoration: none;">Dashboard</a>
                    @else
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" class="link-underline" style="color: rgba(255,255,255,0.85); font-size: 14px; text-decoration: none; padding-bottom: 1px;">Sign in</a>
                        @endif
                    @endauth
                </div>
            </nav>
        </header>

        <main style="flex: 1; display: flex; align-items: center; padding: 48px 20px;">
            <div style="max-width: 640px; margin: 0 auto; width: 100%; text-align: center;">
                <div style="display: inline-flex; align-items: center; justify-content: center; width: 88px; height: 88px; border-radius: 24px; background: rgba(0,0,0,0.04); margin-bottom: 28px;" aria-hidden="true">
                    @yield('icon')
                </div>

                <p class="font-mono" style="font-size: 13px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-soft); margin: 0 0 10px;">Error {{ $code }}</p>
                <h1 class="font-display" style="font-size: clamp(26px, 4vw, 34px); font-weight: 700; line-height: 1.2; margin: 0 0 14px; color: var(--ink);">@yield('title')</h1>
                <p style="font-size: 16px; line-height: 1.6; color: var(--ink-soft); margin: 0 0 32px;">@yield('message')</p>

                <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 12px; margin-bottom: 56px;">
                    @yield('actions')
                </div>

                @if (!empty($discover))
                    <div style="border-top: 1px solid var(--line); padding-top: 28px; text-align: left;">
                        <p class="font-mono" style="font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ink-soft); margin: 0 0 14px; text-align: center;">While you're here — the rest of the Dot Ecosystem ({{ count($discover) }})</p>
                        <div style="display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 8px; scroll-snap-type: x proximity; -webkit-overflow-scrolling: touch;">
                            @foreach ($discover as $platform)
                                <a href="{{ $platform['url'] }}" class="press" style="flex: 0 0 auto; scroll-snap-align: start; display: flex; align-items: center; gap: 8px; padding: 7px 14px 7px 7px; background: #ffffff; border: 1px solid var(--line); border-radius: 999px; text-decoration: none; white-space: nowrap;">
                                    <span class="material-symbols-rounded" aria-hidden="true" style="display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: {{ $platform['accent'] ?? 'var(--accent)' }}; color: #ffffff; font-size: 16px; flex-shrink: 0;">{{ $platform['icon'] ?? 'apps' }}</span>
                                    <span class="font-display" style="font-weight: 600; font-size: 13px; color: var(--ink);">{{ $platform['name'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </main>

        <footer style="background: var(--chrome-soft); padding: 24px 20px;">
            <div style="max-width: 1400px; margin: 0 auto; display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 16px;">
                <p class="font-mono" style="font-size: 12px; color: rgba(255,255,255,0.6); margin: 0;">&copy; {{ date('Y') }} Dot.Billing.</p>
                @if (Route::has('contact'))
                    <a href="{{ route('contact') }}" class="link-underline font-mono" style="font-size: 12px; color: rgba(255,255,255,0.6); text-decoration: none; padding-bottom: 1px;">Contact support</a>
                @endif
            </div>
        </footer>
    </body>
</html>
