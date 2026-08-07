@extends('errors._ecosystem-layout', ['code' => 403])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#c9382e" stroke-width="1.75"><rect x="5" y="11" width="14" height="9" rx="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 11V7a4 4 0 118 0v4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="15.5" r="1.1" fill="#c9382e" stroke="none"/></svg>
@endsection

@section('title', "You don't have access to this")
@section('message', "Either this belongs to a different team, needs a role you don't currently have, or requires signing in first. If you think this is wrong, check with whoever owns this workspace.")

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
