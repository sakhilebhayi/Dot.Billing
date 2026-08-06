<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts — same pairing as welcome.blade.php -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles

        <style>
            :root {
                --paper: #faf6ec;
                --paper-deep: #f1e8d3;
                --ink: #1e2019;
                --ink-soft: #585c4e;
                --gold: #b8860f;
                --gold-bright: #f1c62e;
                --green: #5f7f2c;
                --green-bright: #97c344;
                --line: rgba(30, 32, 25, 0.14);
                --font-display: 'Fraunces', Georgia, serif;
                --font-body: 'IBM Plex Sans', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); font-optical-sizing: auto; }
            .font-mono { font-family: var(--font-mono); }
            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }
        </style>
    </head>
    <body class="antialiased">
        <div class="relative min-h-screen flex flex-col items-center pt-10 pb-6 px-4 overflow-hidden">
            {{-- Same hero photo as welcome.blade.php (antique accounting ledger, Magic Fan),
            desktop-only per the welcome hero's own note (0.8.1): a light-theme photo backdrop
            needs a wide safe zone to keep text legible, so this stays hidden below lg and sits
            behind a near-solid paper gradient rather than a full-strength scrim. --}}
            <div class="hidden lg:block absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1780246033063-b058393796a0?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="hidden lg:block absolute inset-0" style="background: radial-gradient(ellipse 70% 65% at 50% 35%, var(--paper) 0%, rgba(250,246,236,0.94) 45%, rgba(250,246,236,0.7) 72%, rgba(250,246,236,0.35) 100%);"></div>

            <div class="relative z-10 mb-6">
                <x-authentication-card-logo />
            </div>

            <div class="relative z-10 w-full flex flex-col items-center">
                {{ $slot }}
            </div>
        </div>

        @livewireScripts
    </body>
</html>
