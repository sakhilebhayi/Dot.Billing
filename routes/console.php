<?php

use App\Console\Commands\AdvanceSubscriptions;
use App\Console\Commands\GenerateInvoices;
use App\Console\Commands\PublishMetrics;
use App\Console\Commands\ScanBillingAlerts;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Scheduled Platform Jobs ──────────────────────────────────────────────────
// This platform's first scheduled process -- see
// docs/superpowers/specs/2026-08-09-billing-alerts-dunning-gate.md.
Schedule::command(ScanBillingAlerts::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Subscription clock: roll periods and end trials first, then raise any invoice that is still missing.
// Both are idempotent, so a missed day is caught up the next time they run.
Schedule::command(AdvanceSubscriptions::class)
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(GenerateInvoices::class)
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onOneServer();

// Daily aggregate snapshot for Dot.Analytics, after the clock above has rolled and invoiced the day. Queued per
// company; sends nothing until ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET are set. Needs a queue worker.
Schedule::command(PublishMetrics::class)
    ->dailyAt('03:00')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
