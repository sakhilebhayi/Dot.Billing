@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'bg-[var(--paper)] border-[rgba(30,32,25,0.28)] text-[var(--ink)] placeholder:text-[var(--ink-soft)] placeholder:opacity-60 focus:border-[var(--gold)] focus:ring-[var(--gold)] rounded-md shadow-sm disabled:opacity-50']) !!}>
