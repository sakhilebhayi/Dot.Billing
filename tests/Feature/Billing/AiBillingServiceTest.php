<?php

namespace Tests\Feature\Billing;

use App\Livewire\Billing\UsageDashboard;
use App\Models\Team;
use App\Models\User;
use App\Services\AiBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for a config-key mismatch found in an incremental
 * pass: AiBillingService previously read `services.anthropic.key`, but
 * config/services.php only ever defined `services.anthropic.api_key`
 * (sourced from the ANTHROPIC_API_KEY env var). That mismatch meant the
 * service's API key was always empty regardless of environment
 * configuration, so it silently and permanently fell back to canned
 * copy instead of ever calling Claude — the "live AI insights" feature
 * was dead code. These tests pin the corrected config key and the
 * fallback contract so a future refactor can't reintroduce the drift
 * silently.
 */
class AiBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_reads_api_key_from_the_same_config_path_services_php_defines(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key-123']);

        $service = new AiBillingService;
        $apiKey = (new \ReflectionProperty($service, 'apiKey'))->getValue($service);

        $this->assertSame('sk-test-key-123', $apiKey);
    }

    public function test_analyze_spend_falls_back_to_honest_canned_copy_when_no_api_key_is_configured(): void
    {
        config(['services.anthropic.api_key' => '']);

        $team = Team::factory()->create();
        $service = new AiBillingService;

        $result = $service->analyzeSpend($team, ['dot-tasks' => ['api_calls' => 100]]);

        $this->assertSame(
            [
                'Usage is within normal range for your plan.',
                'Consider upgrading to Pro to unlock unlimited API calls.',
            ],
            $result['insights']
        );
        $this->assertSame(0, $result['savings_zar']);
        $this->assertSame(0, $result['tokens_used']);
    }

    public function test_usage_dashboard_component_can_trigger_analysis_without_a_configured_api_key(): void
    {
        config(['services.anthropic.api_key' => '']);

        $user = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($user)
            ->test(UsageDashboard::class)
            ->call('analyzeSpend')
            ->assertSet('aiInsights', [
                'Usage is within normal range for your plan.',
                'Consider upgrading to Pro to unlock unlimited API calls.',
            ]);
    }
}
