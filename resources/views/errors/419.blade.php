@extends('errors._ecosystem-layout', ['code' => 419])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#b8860f" stroke-width="1.75"><circle cx="12" cy="13" r="8" stroke-linecap="round"/><path d="M12 9v4l2.5 2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 2h6" stroke-linecap="round"/></svg>
@endsection

@section('title', 'Your session timed out')
@section('message', 'For your security, this form expires after a while of inactivity. Nothing was lost — go back and try submitting again.')

@section('actions')
    <a href="{{ url()->previous() === url()->current() ? '/' : url()->previous() }}" class="press" style="padding: 12px 24px; background: var(--accent); color: #1e2019; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Go back and retry</a>
    <a href="/" class="press link-underline" style="padding: 12px 24px; color: var(--ink); font-size: 15px; text-decoration: none;">Go home</a>
@endsection
