<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Billing — Plans, invoices &amp; usage for the Dot Ecosystem</title>
        <meta name="description" content="Track your plan, read every invoice, and see what each connected platform is using — the subscription and billing home for the Dot Ecosystem.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

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

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(30, 32, 25, 0.025); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            x-data="{ scrolled: false, mobileMenuOpen: false }"
            @scroll.window="scrolled = window.pageYOffset > 24"
            :class="scrolled ? 'bg-[#faf6ec]/95 backdrop-blur-md border-b border-[var(--line)]' : 'border-b border-transparent'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-2.5 flex items-center justify-between">
                <a href="/" class="flex items-center press">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Billing" class="h-16 sm:h-20 w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="#built" class="link-underline hover:text-[var(--ink)] pb-0.5">What's built</a>
                    <a href="#ledger" class="link-underline hover:text-[var(--ink)] pb-0.5">Domain model</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--ink)] hover:bg-[#33362a] text-[var(--paper)] text-sm font-display font-semibold rounded-lg transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--ink-soft)] hover:text-[var(--ink)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--ink)] hover:bg-[#33362a] text-[var(--paper)] text-sm font-display font-semibold rounded-lg transition-colors">
                                    Create account
                                </a>
                            @endif
                        @endauth

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press p-2 -mr-2 text-[var(--ink)]" aria-label="Toggle menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="md:hidden border-t border-[var(--line)] bg-[var(--paper)]"
                 style="display: none;">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#built" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">What's built</a>
                    <a href="#ledger" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Domain model</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative overflow-hidden pt-32 sm:pt-36">
            <div class="relative max-w-[1400px] mx-auto px-5 sm:px-8 pb-16 sm:pb-20 grid lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)] gap-12 lg:gap-8 items-center">
                <div class="reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-6">
                        Billing for the Dot Ecosystem
                    </p>

                    <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-[3.4rem] leading-[1.08] tracking-tight text-[var(--ink)] mb-6">
                        Your plan, your invoices, and where the spend went.
                    </h1>

                    <p class="text-lg text-[var(--ink-soft)] leading-relaxed max-w-xl mb-10">
                        Dot.Billing is the subscription and billing home for the InfoDot ecosystem: track your active plan, read every invoice, and see how much each connected platform is using — with plain-language spend commentary when you want it.
                    </p>

                    @guest
                        <div class="flex flex-wrap items-center gap-4">
                            <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--gold-bright)] hover:brightness-95 text-[var(--ink)] font-display font-semibold rounded-lg transition-[filter]">
                                Create account
                            </a>
                            <a href="#built" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--ink)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--ink-soft)] transition-colors">
                                See what's built
                            </a>
                        </div>
                    @endguest
                </div>

                <!-- Signature element: line-art invoice, echoing the real logo's "BILL" icon -->
                <div class="reveal hidden lg:flex justify-center" data-reveal aria-hidden="true">
                    <svg viewBox="0 0 320 380" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full max-w-sm h-auto">
                        <rect x="4" y="4" width="272" height="372" rx="2" fill="var(--paper-deep)" stroke="var(--ink)" stroke-opacity="0.16" stroke-width="1.5"/>
                        <path d="M276 4L228 4L276 52Z" fill="var(--paper)" stroke="var(--ink)" stroke-opacity="0.16" stroke-width="1.5" stroke-linejoin="round"/>
                        <rect x="36" y="52" width="90" height="18" rx="2" fill="var(--gold-bright)" fill-opacity="0.9"/>
                        <g stroke="var(--ink)" stroke-opacity="0.4" stroke-width="1.5" stroke-linecap="round">
                            <line x1="36" y1="104" x2="244" y2="104"/>
                            <line x1="36" y1="132" x2="244" y2="132"/>
                            <line x1="36" y1="160" x2="200" y2="160"/>
                            <line x1="36" y1="196" x2="244" y2="196"/>
                            <line x1="36" y1="224" x2="244" y2="224"/>
                            <line x1="36" y1="252" x2="160" y2="252"/>
                        </g>
                        <line x1="36" y1="288" x2="244" y2="288" stroke="var(--ink)" stroke-opacity="0.16" stroke-width="1.5"/>
                        <text x="36" y="326" font-family="IBM Plex Mono, monospace" font-size="20" fill="var(--ink)" fill-opacity="0.55">TOTAL</text>
                        <text x="244" y="326" font-family="IBM Plex Mono, monospace" font-size="20" fill="var(--green)" text-anchor="end" font-weight="600">R —.—</text>
                        <circle cx="230" cy="352" r="20" fill="none" stroke="var(--green-bright)" stroke-width="2.5"/>
                        <path d="M222 352L228 358L239 345" stroke="var(--green-bright)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <!-- chevron, echoing the logo's green arrow -->
                        <path d="M298 150L328 190L298 230" stroke="var(--green-bright)" stroke-width="14" stroke-linecap="round" stroke-linejoin="round" opacity="0.9"/>
                    </svg>
                </div>
            </div>

            <!-- Field strip — the real domain entities, not a fabricated metric -->
            <div class="relative w-full border-y border-[var(--line)] bg-[var(--paper-deep)]/60">
                <div class="max-w-[1400px] mx-auto px-5 sm:px-8 py-4 flex flex-wrap gap-x-8 gap-y-2 font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--ink-soft)]">
                    <span>Plans</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Subscriptions</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Invoices</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Payments</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Usage records</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Credits &amp; alerts</span>
                </div>
            </div>
        </section>

        <!-- What's built -->
        <section id="built" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">What's built today</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        Real components, running against a real database
                    </h2>
                    <p class="text-[var(--ink-soft)] leading-relaxed mt-4">
                        This is a working application skeleton, not a mockup — but the domain is still thin. Here's what actually ships in the repo today.
                    </p>
                </div>

                <div class="grid md:grid-cols-2 border-t border-[var(--line)]">
                    @php
                        $built = [
                            ['tag' => 'Sso', 'title' => 'Ecosystem sign-in', 'body' => 'Authenticate through the same SSO handoff as every other Dot platform, backed by Sanctum and scoped to your Jetstream team.'],
                            ['tag' => 'Overview', 'title' => 'Subscription overview', 'body' => 'Your current plan, billing cycle, trial status, and next invoice, from a single Livewire dashboard component.'],
                            ['tag' => 'Invoices', 'title' => 'Invoice table', 'body' => 'A running list of invoices with subtotal, tax, total, and status — draft, open, paid, void, or uncollectible.'],
                            ['tag' => 'Usage', 'title' => 'Usage dashboard', 'body' => 'Per-platform metric consumption across the ecosystem — the same records other Dot platforms report through.'],
                            ['tag' => 'Ai', 'title' => 'Spend commentary', 'body' => 'AiBillingService calls Claude directly for plain-language spend insights, with a safe canned-copy fallback if no key is configured.'],
                            ['tag' => 'Team', 'title' => 'Team-scoped by default', 'body' => 'Every billing record is scoped to your current team\'s data — you never see another team\'s plan, invoices, or usage.'],
                        ];
                    @endphp
                    @foreach ($built as $i => $f)
                        <div class="row-hover border-b border-[var(--line)] {{ $i % 2 === 0 ? 'md:border-r' : '' }} px-1 py-8 sm:py-10 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--gold)] mb-3">{{ $f['tag'] }}</p>
                            <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-2.5">{{ $f['title'] }}</h3>
                            <p class="text-[var(--ink-soft)] leading-relaxed max-w-md">{{ $f['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Domain model / ledger -->
        <section id="ledger" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--paper-deep)] border-y border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)] gap-12 lg:gap-16">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">The ledger</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-8">
                            Nine models, one team-scoped schema
                        </h2>

                        <div class="border-t border-[var(--line)]">
                            @php
                                $models = [
                                    ['name' => 'BillingPlan', 'table' => 'billing_plans', 'purpose' => 'Subscription tier — pricing, seat limit, storage cap, feature list'],
                                    ['name' => 'BillingSubscription', 'table' => 'billing_subscriptions', 'purpose' => 'Team-to-plan mapping — status, billing cycle, period dates, trial'],
                                    ['name' => 'BillingInvoice', 'table' => 'billing_invoices', 'purpose' => 'Generated invoice — subtotal, tax, total, status, due/paid dates'],
                                    ['name' => 'BillingInvoiceItem', 'table' => 'billing_invoice_items', 'purpose' => 'Line item on an invoice'],
                                    ['name' => 'BillingPayment', 'table' => 'billing_payments', 'purpose' => 'Payment attempt against an invoice — status, method, failure reason'],
                                    ['name' => 'BillingPaymentMethod', 'table' => 'billing_payment_methods', 'purpose' => 'Stored card/EFT reference — last4, brand, expiry'],
                                    ['name' => 'BillingUsageRecord', 'table' => 'billing_usage_records', 'purpose' => 'Per-platform metric consumption — the hook other platforms report through'],
                                    ['name' => 'BillingCredit', 'table' => 'billing_credits', 'purpose' => 'Account credit or adjustment, with optional expiry'],
                                    ['name' => 'BillingAlert', 'table' => 'billing_alerts', 'purpose' => 'Budget or threshold alert — type, metric, value, status'],
                                ];
                            @endphp
                            @foreach ($models as $m)
                                <div class="row-hover grid sm:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)] gap-2 sm:gap-6 py-4 border-b border-[var(--line)] transition-colors reveal" data-reveal>
                                    <p class="font-mono text-sm text-[var(--ink)]">{{ $m['name'] }}</p>
                                    <p class="text-sm text-[var(--ink-soft)] leading-relaxed">{{ $m['purpose'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="reveal lg:pt-[4.5rem]" data-reveal>
                        <div class="border border-[var(--line)] rounded-lg p-7 bg-[var(--paper)]">
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--green)] mb-4">Modeled, not yet built</p>
                            <ul class="space-y-4">
                                <li class="flex gap-3">
                                    <span class="font-mono text-[var(--gold)] shrink-0">›</span>
                                    <p class="text-sm text-[var(--ink-soft)] leading-relaxed">Stripe integration — no SDK, webhook route, or charge-creation code exists here yet. The `stripe_*` columns are foreign-reference fields for an integration that isn't wired up.</p>
                                </li>
                                <li class="flex gap-3">
                                    <span class="font-mono text-[var(--gold)] shrink-0">›</span>
                                    <p class="text-sm text-[var(--ink-soft)] leading-relaxed">Domain events for subscription, payment, and alert lifecycle changes — none are emitted yet.</p>
                                </li>
                                <li class="flex gap-3">
                                    <span class="font-mono text-[var(--gold)] shrink-0">›</span>
                                    <p class="text-sm text-[var(--ink-soft)] leading-relaxed">A usage-ingestion endpoint for other Dot platforms to report consumption — the table exists; nothing writes to it yet.</p>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-32 px-5 sm:px-8">
            <div class="relative max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                    One place to see your plan and your spend
                </h2>
                <p class="text-[var(--ink-soft)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Sign in through the ecosystem SSO you already use for the rest of the Dot platforms.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--gold-bright)] hover:brightness-95 text-[var(--ink)] font-display font-semibold rounded-lg transition-[filter]">
                            Create account
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--ink)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--ink-soft)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Billing" class="h-11 w-auto opacity-90">
                </a>
                <p class="font-mono text-xs tracking-wide text-[var(--ink-soft)]">
                    &copy; {{ date('Y') }} Dot.Billing. Billing for the Dot Ecosystem.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
