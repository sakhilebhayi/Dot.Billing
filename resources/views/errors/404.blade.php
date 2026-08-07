@extends('errors._ecosystem-layout', ['code' => 404])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#f1c62e" stroke-width="1.75"><circle cx="11" cy="11" r="7" stroke-linecap="round"/><path d="M21 21l-4.35-4.35" stroke-linecap="round"/><path d="M9 9.5c0-1.1.9-1.75 2-1.75s2 .65 2 1.75c0 .9-.6 1.3-1.2 1.7-.5.35-.8.7-.8 1.3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="11" cy="14.75" r="0.15" fill="#f1c62e" stroke="none"/></svg>
@endsection

@section('title', "We couldn't find that page")
@section('message', "The page you're looking for may have moved, been renamed, or never existed. Double-check the link, or head somewhere we know works.")

@section('actions')
    <a href="/" class="press" style="padding: 12px 24px; background: var(--accent); color: #1e2019; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Go home</a>
    @auth
    <a href="{{ route('dashboard') }}" class="press link-underline" style="padding: 12px 24px; color: var(--ink); font-size: 15px; text-decoration: none;">Go to dashboard</a>
    @else
        @if (Route::has('login'))
    <a href="{{ route('login') }}" class="press link-underline" style="padding: 12px 24px; color: var(--ink); font-size: 15px; text-decoration: none;">Sign in</a>
        @endif
    @endauth
@endsection
