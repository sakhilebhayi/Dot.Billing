<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Billing — Subscriptions, Invoices & Usage</title>
        <meta name="description" content="Track your plan, view and pay invoices, and monitor per-platform usage across the Dot Ecosystem — with AI-generated spend commentary.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="bg-gray-950 text-gray-100 antialiased">

        <!-- Header -->
        <header class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" x-data="{ scrolled: false, mobileMenuOpen: false }"
                @scroll.window="scrolled = window.pageYOffset > 50"
                :class="scrolled ? 'bg-gray-950/95 backdrop-blur-xl shadow-lg border-b border-gray-800' : 'bg-transparent'">
            <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <a href="/" class="flex items-center gap-3 group">
                        <img src="{{ asset('images/logo.png') }}" alt="Dot.Billing" class="h-14 w-auto transform group-hover:scale-105 transition-transform duration-300">
                        <p class="hidden sm:block text-xs text-emerald-400 font-medium border-l border-gray-700 pl-3">Billing & Usage</p>
                    </a>

                    <div class="hidden md:flex items-center gap-8">
                        <a href="#features" class="text-gray-300 hover:text-emerald-400 transition-colors font-medium">Features</a>
                        <a href="#platform" class="text-gray-300 hover:text-emerald-400 transition-colors font-medium">Platform</a>
                    </div>

                    @if (Route::has('login'))
                        <div class="flex items-center gap-3">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="hidden sm:flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-gray-900 font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-emerald-500/30 transform hover:scale-105">
                                    <span>Dashboard</span>
                                </a>
                            @else
                                <a href="{{ route('login') }}" class="hidden sm:block px-4 py-2 text-gray-300 hover:text-white transition-colors font-medium">Sign In</a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-gray-900 font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-emerald-500/30 transform hover:scale-105">
                                        <span>Get Started</span>
                                    </a>
                                @endif
                            @endauth
                            <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2 text-gray-400 hover:text-white">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                                    <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    @endif
                </div>

                <div x-show="mobileMenuOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform scale-95"
                     x-transition:enter-end="opacity-100 transform scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 transform scale-100"
                     x-transition:leave-end="opacity-0 transform scale-95"
                     class="md:hidden mt-4 py-4 border-t border-gray-800"
                     style="display: none;">
                    <div class="flex flex-col gap-2">
                        <a href="#features" class="px-4 py-2 text-gray-300 hover:text-emerald-400 hover:bg-gray-800 rounded-lg transition-colors">Features</a>
                        @guest
                            <a href="{{ route('login') }}" class="px-4 py-2 text-gray-300 hover:text-emerald-400 hover:bg-gray-800 rounded-lg transition-colors">Sign In</a>
                        @endguest
                    </div>
                </div>
            </nav>
        </header>

        <!-- Hero -->
        <section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-20">
            <!-- Photographic Background: real desk-with-calculator-and-invoices photo by Cht Gsml (@karepesinde), unsplash.com/photos/desk-with-calculator-binders-notebook-and-glasses--6LEDthF1AI -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1762427355235-dd22e5cb010c?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-gray-950 via-gray-950/85 to-gray-950/60"></div>
            <div class="absolute inset-0 bg-gradient-to-r from-gray-950 via-gray-950/40 to-transparent"></div>

            <div class="relative z-10 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center">
                <div class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-500/10 border border-emerald-500/20 rounded-full text-emerald-400 text-sm font-medium mb-8">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    <span>Billing for the Dot Ecosystem</span>
                </div>

                <h1 class="text-5xl lg:text-7xl font-bold leading-tight mb-6">
                    <span class="bg-gradient-to-r from-white via-gray-100 to-gray-300 bg-clip-text text-transparent">One Place for Your</span><br>
                    <span class="bg-gradient-to-r from-emerald-400 via-emerald-500 to-emerald-600 bg-clip-text text-transparent">Plan, Invoices & Usage</span>
                </h1>

                <p class="text-xl text-gray-400 leading-relaxed max-w-2xl mx-auto mb-10">
                    Dot.Billing is the subscription and billing home for the InfoDot ecosystem: track your active plan, view and pay invoices, monitor how much each connected platform is using, and get AI-generated commentary on where the spend is going.
                </p>

                @guest
                    <div class="flex flex-wrap items-center justify-center gap-4">
                        <a href="{{ route('register') }}" class="group flex items-center gap-2 px-8 py-4 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-gray-900 font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-emerald-500/30 transform hover:scale-105">
                            <span>Get Started</span>
                        </a>
                        <a href="#features" class="flex items-center gap-2 px-8 py-4 bg-gray-800 hover:bg-gray-700 text-white font-semibold rounded-xl transition-all duration-300 border border-gray-700 hover:border-gray-600">
                            <span>See What's Inside</span>
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Features -->
        <section id="features" class="py-24 px-4 sm:px-6 lg:px-8 bg-gray-950/50 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-b from-transparent via-emerald-500/5 to-transparent"></div>

            <div class="relative z-10 max-w-7xl mx-auto">
                <div class="text-center mb-16">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-500/10 border border-emerald-500/20 rounded-full text-emerald-400 text-sm font-medium mb-6">
                        <span>What's Built Today</span>
                    </div>
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                        A Clear View of Your<br>
                        <span class="bg-gradient-to-r from-emerald-400 to-emerald-600 bg-clip-text text-transparent">Subscription & Spend</span>
                    </h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">Dot.Billing models billing state — plans, subscriptions, invoices, payments, credits, alerts — rather than acting as its own payments processor.</p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                    @foreach([
                        ['title' => 'Subscription Overview', 'desc' => 'See your current plan, billing cycle, trial status, and next invoice at a glance from a single Livewire dashboard component.', 'color' => 'from-emerald-500 to-emerald-600'],
                        ['title' => 'Invoice Table', 'desc' => 'A running table of invoices with subtotal, tax, total, and status — draft, open, paid, void, or uncollectible.', 'color' => 'from-blue-500 to-blue-600'],
                        ['title' => 'Usage Dashboard', 'desc' => 'Per-platform metric consumption across the ecosystem, recorded as usage records that other Dot platforms report through.', 'color' => 'from-purple-500 to-purple-600'],
                        ['title' => 'AI Spend Commentary', 'desc' => 'AiBillingService calls Claude directly to generate plain-language spend insights, with a safe canned-copy fallback if no API key is configured.', 'color' => 'from-amber-500 to-amber-600'],
                        ['title' => 'Credits & Alerts (modeled)', 'desc' => 'Account credit/adjustment records with optional expiry, and budget or threshold alerts, are part of the schema today.', 'color' => 'from-pink-500 to-pink-600'],
                        ['title' => 'Ecosystem SSO', 'desc' => 'Authenticate through the same ecosystem SSO handoff as every other Dot platform, backed by Sanctum and team-scoped by Jetstream.', 'color' => 'from-indigo-500 to-indigo-600'],
                    ] as $f)
                        <div class="group bg-gradient-to-br from-gray-900 to-gray-950 p-8 rounded-2xl border border-gray-800 hover:border-emerald-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-emerald-500/10 transform hover:-translate-y-2">
                            <div class="w-14 h-14 bg-gradient-to-br {{ $f['color'] }} rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg">
                                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3v-6m-3 6v-9m-2 9V7a2 2 0 012-2h6a2 2 0 012 2v10a2 2 0 01-2 2H7a2 2 0 01-2-2z"></path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-white mb-3 group-hover:text-emerald-400 transition-colors">{{ $f['title'] }}</h3>
                            <p class="text-gray-400 leading-relaxed">{{ $f['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Platform -->
        <section id="platform" class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-gray-950 via-gray-900 to-gray-950"></div>

            <div class="relative z-10 max-w-5xl mx-auto">
                <div class="text-center mb-14">
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">Built on the <span class="bg-gradient-to-r from-emerald-400 to-emerald-600 bg-clip-text text-transparent">InfoDot Stack</span></h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">Dot.Billing runs on the ecosystem's shared PostgreSQL instance and leans on Stripe identifiers as foreign references — it is honest about what it models versus what it has actually built.</p>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach([
                        ['label' => 'Laravel 12 / PHP 8.4', 'desc' => 'Jetstream + Fortify auth'],
                        ['label' => 'Livewire 3 + Alpine.js', 'desc' => 'Server-rendered dashboard'],
                        ['label' => 'PostgreSQL 16', 'desc' => 'Shared ecosystem instance'],
                        ['label' => 'Anthropic Claude', 'desc' => 'Spend commentary, with fallback'],
                    ] as $item)
                        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 text-center">
                            <p class="font-bold text-sm text-white mb-1">{{ $item['label'] }}</p>
                            <p class="text-xs text-gray-400">{{ $item['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-gray-950 via-emerald-950/30 to-gray-950"></div>
            <div class="relative z-10 max-w-4xl mx-auto text-center">
                <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                    See Your Plan and <span class="bg-gradient-to-r from-emerald-400 to-emerald-600 bg-clip-text text-transparent">Spend in One Place</span>
                </h2>
                <p class="text-xl text-gray-400 mb-10 max-w-2xl mx-auto">Get started with Dot.Billing.</p>
                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="group flex items-center gap-2 px-10 py-4 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-gray-900 font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-emerald-500/30 transform hover:scale-105">
                            <span>Get Started</span>
                        </a>
                        <a href="{{ route('login') }}" class="flex items-center gap-2 px-10 py-4 bg-gray-800 hover:bg-gray-700 text-white font-semibold rounded-xl transition-all duration-300 border border-gray-700 hover:border-gray-600">
                            <span>Sign In</span>
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-12 px-4 sm:px-6 lg:px-8 border-t border-gray-800 bg-gray-950/50">
            <div class="max-w-7xl mx-auto">
                <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Billing" class="h-12 w-auto">
                    <p class="text-gray-400 text-sm">&copy; {{ date('Y') }} Dot.Billing. Part of the Dot Ecosystem.</p>
                </div>
            </div>
        </footer>

    </body>
</html>
