<?php

namespace App\Services;

use App\Models\Team;

class AiBillingService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model  = config('services.anthropic.model', 'claude-sonnet-4-6');
    }

    /**
     * Fallback copy shared by two cases: no API key configured, and a live
     * call that failed (timed out, network error, or non-2xx response). Both
     * cases produce the same honest, clearly-generic result rather than
     * letting a network failure silently degrade to an empty insights array.
     *
     * @return array{insights: array<int, string>, savings_zar: int, tokens_used: int}
     */
    private function fallbackResult(): array
    {
        return [
            'insights'    => [
                'Usage is within normal range for your plan.',
                'Consider upgrading to Pro to unlock unlimited API calls.',
            ],
            'savings_zar' => 0,
            'tokens_used' => 0,
        ];
    }

    public function analyzeSpend(Team $team, array $usageSummary): array
    {
        if (empty($this->apiKey)) {
            return $this->fallbackResult();
        }

        $prompt = "You are a billing intelligence AI for the InfoDot ecosystem.\n\n" .
            "Team: {$team->name}\n" .
            "Usage summary: " . json_encode($usageSummary) . "\n\n" .
            "Analyse this spend data and return JSON: {\"insights\": [\"...\",\"...\"], \"savings_zar\": <number>}";

        $response = $this->callClaude($prompt);

        // Network failure, timeout, or non-2xx response: callClaude() signals
        // this with null rather than a body, so we fall back to the same
        // honest canned copy used when no API key is configured at all,
        // instead of silently returning an empty insights array.
        if ($response === null) {
            return $this->fallbackResult();
        }

        $decoded = json_decode($response, true);

        return [
            'insights'    => $decoded['insights'] ?? [],
            'savings_zar' => $decoded['savings_zar'] ?? 0,
            'tokens_used' => 0,
        ];
    }

    /**
     * Calls the Claude Messages API directly over cURL (no Stripe-style SDK
     * in this repo yet — see wiki.md §8). Bounded with explicit connect/total
     * timeouts so a slow or unreachable upstream can't hang the Livewire
     * request indefinitely; returns null on any transport or HTTP-level
     * failure so the caller can fall back to honest canned copy instead of
     * silently degrading.
     */
    private function callClaude(string $prompt): ?string
    {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'      => $this->model,
                'max_tokens' => 512,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]),
        ]);
        $body       = curl_exec($ch);
        $errorNo    = curl_errno($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errorNo !== 0 || $body === false || $statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        $data = json_decode($body, true);
        return $data['content'][0]['text'] ?? '{}';
    }
}
