<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsClient;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Console\Command;
use Throwable;

class PublishMetrics extends Command
{
    protected $signature = 'billing:publish-metrics
        {--company= : Publish for this InfoDot company uuid only}
        {--dry-run : Print what would be sent; send and queue nothing}';

    protected $description = 'Queue one daily aggregate metrics snapshot per company for Dot.Analytics. Sends nothing until ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET are both set.';

    public function handle(MetricsCollector $collector, AnalyticsClient $client): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('company');

        if (! $dry && ! $client->enabled()) {
            $this->info('Analytics publishing is disabled: set ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET to enable it. Nothing was sent.');

            return self::SUCCESS;
        }

        $companies = $collector->companies(filled($only) ? (string) $only : null);

        if (filled($only) && $companies->isEmpty()) {
            $this->error('No company with that InfoDot uuid. Personal teams and teams not linked to InfoDot are never published.');

            return self::FAILURE;
        }

        $now = now();
        $queued = 0;
        $failed = 0;

        foreach ($companies as $team) {
            try {
                $result = $collector->collect($team, $now);
                $payload = $client->payload($team, $result['metrics'], $now);

                foreach ($result['omitted'] as $key => $reason) {
                    $this->warn("Company {$team->infodot_team_uuid}: {$key} omitted ({$reason}).");
                }

                if ($dry) {
                    $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    continue;
                }

                if ($client->publish($team, $result['metrics'], $now)) {
                    $queued++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("Could not build the snapshot for company {$team->infodot_team_uuid}: ".class_basename($e));
                report($e);
            }
        }

        $this->info($dry ? "Dry run: {$companies->count()} company snapshot(s) shown, nothing sent." : "Queued {$queued} snapshot(s)".($failed ? ", {$failed} failed to build" : '').'.');

        return self::SUCCESS;
    }
}
