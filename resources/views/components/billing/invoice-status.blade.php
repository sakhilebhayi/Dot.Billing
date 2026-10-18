@props(['invoice'])
@php
    $overdue = $invoice->isOverdue();
    $grace = $invoice->status === 'open' && $invoice->isInGrace();
    [$class, $label] = match (true) {
        $overdue => ['overdue', 'Overdue'],
        $grace => ['grace', 'Grace until '.$invoice->grace_until->format('j M')],
        default => [$invoice->status, ucfirst($invoice->status)],
    };
@endphp
<span class="pill {{ $class }}">{{ $label }}</span>
