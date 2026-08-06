@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-mono text-xs font-medium tracking-wide uppercase text-[var(--ink-soft)]']) }}>
    {{ $value ?? $slot }}
</label>
