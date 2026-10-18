<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Claude to comment on a team's platform usage.
 *
 * Honesty rule: this returns insights only when a model really produced them for this team's numbers.
 * No API key, a failed or slow call, an unusable answer: all return null, and the caller shows
 * "AI analysis unavailable" and stores nothing. There is no fallback copy and there never should be:
 * canned advice dressed as analysis is worse than no analysis.
 */
class AiBillingService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private string $apiKey;

    private string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.api_key', '');
        $this->model = (string) config('services.anthropic.model', 'claude-sonnet-4-6');
    }

    /**
     * @param  array<string, mixed>  $usageSummary
     * @return array{insights: list<string>, tokens_used: int}|null null = unavailable
     */
    public function analyzeSpend(Team $team, array $usageSummary): ?array
    {
        if ($this->apiKey === '') {
            return null;
        }

        $prompt = "You are a billing analyst for the InfoDot ecosystem.\n\n".
            "Team: {$team->name}\n".
            'Usage totals by platform and metric: '.json_encode($usageSummary)."\n\n".
            'Say what stands out in these numbers and nothing you cannot see in them. '.
            'Reply with only JSON: {"insights": ["...", "..."]}';

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                ])
                ->acceptJson()
                ->asJson()
                ->post(self::ENDPOINT, [
                    'model' => $this->model,
                    'max_tokens' => 512,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('AI spend analysis unreachable.', ['error' => class_basename($e)]);

            return null;
        } catch (Throwable $e) {
            Log::warning('AI spend analysis failed.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('AI spend analysis refused.', ['status' => $response->status()]);

            return null;
        }

        $insights = $this->insightsFrom((string) data_get($response->json(), 'content.0.text', ''));

        if ($insights === []) {
            return null;
        }

        return [
            'insights' => $insights,
            'tokens_used' => (int) data_get($response->json(), 'usage.input_tokens', 0) + (int) data_get($response->json(), 'usage.output_tokens', 0),
        ];
    }

    /**
     * The model is asked for bare JSON but may wrap it in a code fence. Anything that does not yield at
     * least one non-empty string insight is treated as no answer.
     *
     * @return list<string>
     */
    private function insightsFrom(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;

        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded) || ! is_array($decoded['insights'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($i) => is_string($i) ? trim($i) : '', $decoded['insights']),
            fn (string $i) => $i !== ''
        ));
    }
}
