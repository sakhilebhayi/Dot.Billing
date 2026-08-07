@extends('errors._ecosystem-layout', ['code' => 429])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#b8860f" stroke-width="1.75"><circle cx="12" cy="13" r="8" stroke-linecap="round"/><path d="M10 10v6M14 10v6" stroke-linecap="round"/><path d="M9 2h6" stroke-linecap="round"/></svg>
@endsection

@section('title', 'Slow down a little')
@section('message', "You've made a few too many requests in a short space of time — a safeguard that protects everyone's access. Wait a minute and try again.")

@section('actions')
    <a href="/" class="press" style="padding: 12px 24px; background: var(--accent); color: #1e2019; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Go home</a>
@endsection
