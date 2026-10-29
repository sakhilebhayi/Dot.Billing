<x-app-layout>
<div class="page-pad" style="padding:2rem 2.5rem 3rem;">
    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">Seats</h1>
        <p style="font-size:0.78rem;color:#71717a;margin:0;">How many people your plan covers, and how to add more.</p>
    </div>

    @if(session('billing_error'))
        <div class="state-line bad" role="alert" style="margin-bottom:1.25rem;"><span class="dot" aria-hidden="true"></span><span>{{ session('billing_error') }}</span></div>
    @endif

    <livewire:billing.seats-panel />
</div>
</x-app-layout>
