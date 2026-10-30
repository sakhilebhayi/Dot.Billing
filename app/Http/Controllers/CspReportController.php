<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives Content-Security-Policy violation reports while the policy is report-only, so an enforcing policy can be designed from what the
 * pages really load instead of guessed. It keeps almost nothing: the directive and the blocked HOST (never the full address, which can carry
 * tokens in its query), logged at most once per pair per day. The body is size-limited and never echoed, and the answer is always 204.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $json = json_decode(substr((string) $request->getContent(), 0, 8192), true);

        foreach (array_slice($this->reportsFrom($json), 0, 5) as $report) {
            if (! is_array($report)) {
                continue;
            }

            $directive = preg_replace('/[^a-z-]/', '', strtolower((string) ($report['effective-directive'] ?? $report['effectiveDirective'] ?? $report['violated-directive'] ?? '')));
            $blocked = (string) ($report['blocked-uri'] ?? $report['blockedURL'] ?? '');
            $host = in_array($blocked, ['inline', 'eval', 'self', ''], true)
                ? ($blocked ?: 'none')
                : (strtolower((string) parse_url($blocked, PHP_URL_HOST)) ?: 'other');

            // The caps keep an unauthenticated sender from minting unbounded cache keys.
            $directive = substr((string) $directive, 0, 40);
            $host = substr((string) preg_replace('/[^a-z0-9.\-]/', '', $host), 0, 100);

            if ($directive !== '' && Cache::add("csp:$directive:$host:".now()->format('Y-m-d'), 1, 90000)) {
                Log::info('csp.violation', ['directive' => $directive, 'blocked_host' => $host]);
            }
        }

        return response()->noContent();
    }

    /**
     * Two shapes exist: {"csp-report": {...}} (report-uri) and [{"type": "csp-violation", "body": {...}}] (Reporting API).
     *
     * @return array<int, mixed>
     */
    private function reportsFrom(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        if (isset($json['csp-report'])) {
            return [$json['csp-report']];
        }

        if (! array_is_list($json)) {
            return [];
        }

        return array_map(fn ($entry) => is_array($entry) ? ($entry['body'] ?? []) : [], $json);
    }
}
