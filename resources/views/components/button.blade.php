<button {{ $attributes->merge(['type' => 'submit', 'class' => 'press inline-flex items-center px-5 py-2.5 bg-[var(--gold-bright)] hover:brightness-95 border border-transparent rounded-lg font-display font-semibold text-sm text-[var(--ink)] transition-[filter] focus:outline-none focus:ring-2 focus:ring-[var(--gold)] focus:ring-offset-2 focus:ring-offset-[var(--paper-deep)] disabled:opacity-50']) }}>
    {{ $slot }}
</button>
