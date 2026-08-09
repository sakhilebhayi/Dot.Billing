<?php

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
