<?php

namespace Tests\Feature\Billing;

use App\Livewire\Billing\UsageDashboard;
use App\Models\BillingUsageRecord;
use App\Models\Team;
use App\Services\AiBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

/**
 * The AI panel only ever shows analysis a model actually produced for this team's numbers. With no key,
 * a failed call, or an unusable answer it says "AI analysis unavailable" and stores and shows nothing:
 * never canned advice presented as if it were an analysis.
 */
class AiBillingServiceTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private function reply(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 20]];
    }

    private function usage(Team $team): void
    {
        BillingUsageRecord::create(['team_id' => $team->id, 'platform' => 'dot.files', 'metric' => 'storage_gb', 'quantity' => 42.5, 'recorded_at' => now()]);
    }

    public function test_service_reads_api_key_from_the_same_config_path_services_php_defines(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key-123']);

        $service = new AiBillingService;
        $apiKey = (new \ReflectionProperty($service, 'apiKey'))->getValue($service);

        $this->assertSame('sk-test-key-123', $apiKey);
    }

    public function test_without_a_key_there_is_no_analysis_and_no_http_call(): void
    {
        config(['services.anthropic.api_key' => '']);
        Http::fake();
        $team = $this->makeOwner()->currentTeam;

        $this->assertNull((new AiBillingService)->analyzeSpend($team, ['dot.files' => ['storage_gb' => 42]]));

        Http::assertNothingSent();
    }

    public function test_a_real_answer_is_returned_as_the_models_own_insights(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key-123']);
        Http::fake([self::ENDPOINT => Http::response($this->reply('{"insights": ["Storage grew faster than seats this month.", "dot.files is your largest consumer."]}'))]);
        $team = $this->makeOwner()->currentTeam;

        $result = (new AiBillingService)->analyzeSpend($team, ['dot.files' => ['storage_gb' => 42]]);

        $this->assertSame(['Storage grew faster than seats this month.', 'dot.files is your largest consumer.'], $result['insights']);
        $this->assertSame(30, $result['tokens_used']);
    }

    public function test_it_sends_the_documented_headers_and_a_bounded_request(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key-123', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        $seen = null;
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response($this->reply('{"insights": ["ok"]}'));
        });
        $team = $this->makeOwner()->currentTeam;

        (new AiBillingService)->analyzeSpend($team, ['dot.files' => ['storage_gb' => 42]]);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === self::ENDPOINT
                && $request->method() === 'POST'
                && $request->hasHeader('x-api-key', 'sk-test-key-123')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && str_contains($request->header('Content-Type')[0] ?? '', 'application/json')
                && $body['model'] === 'claude-sonnet-4-6'
                && $body['max_tokens'] <= 1024
                && ! isset($body['stream'])
                && ! array_key_exists('Authorization', $request->headers());
        });
        $this->assertLessThanOrEqual(15, (int) $seen['timeout']);
        $this->assertLessThanOrEqual(5, (int) $seen['connect_timeout']);
    }

    public function test_failures_of_every_kind_mean_unavailable_not_filler(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key-123']);
        $team = $this->makeOwner()->currentTeam;
        $usage = ['dot.files' => ['storage_gb' => 42]];

        $cases = [
            'server error' => Http::response('boom', 500),
            'rate limited' => Http::response(['error' => 'rate'], 429),
            'bad key' => Http::response(['error' => 'auth'], 401),
            'not json' => Http::response($this->reply('Sure! Here is some advice in prose.')),
            'json without insights' => Http::response($this->reply('{"note": "nothing"}')),
            'empty insights' => Http::response($this->reply('{"insights": []}')),
            'non-string insights' => Http::response($this->reply('{"insights": [{"a": 1}, 7, null]}')),
            'no content' => Http::response(['content' => []]),
        ];

        foreach ($cases as $label => $response) {
            Http::fake([self::ENDPOINT => $response]);
            $this->assertNull((new AiBillingService)->analyzeSpend($team, $usage), $label);
        }

        Http::fake(fn () => throw new ConnectionException('timed out'));
        $this->assertNull((new AiBillingService)->analyzeSpend($team, $usage), 'timeout');
    }

    public function test_the_answer_may_arrive_inside_a_code_fence(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key-123']);
        Http::fake([self::ENDPOINT => Http::response($this->reply("```json\n{\"insights\": [\"Fenced but real.\"]}\n```"))]);
        $team = $this->makeOwner()->currentTeam;

        $this->assertSame(['Fenced but real.'], (new AiBillingService)->analyzeSpend($team, ['p' => ['m' => 1]])['insights']);
    }

    public function test_the_component_shows_unavailable_and_stores_nothing_without_a_key(): void
    {
        config(['services.anthropic.api_key' => '']);
        $owner = $this->makeOwner();
        $this->usage($owner->currentTeam);

        Livewire::actingAs($owner)->test(UsageDashboard::class)
            ->call('analyzeSpend')
            ->assertSet('aiInsights', [])
            ->assertSet('aiUnavailable', true)
            ->assertSee('AI analysis unavailable')
            ->assertDontSee('Consider upgrading')
            ->assertDontSee('within normal range');
    }

    public function test_the_component_shows_real_insights_labelled_as_ai_generated(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key-123']);
        Http::fake([self::ENDPOINT => Http::response($this->reply('{"insights": ["Distinctive real insight about storage growth."]}'))]);
        $owner = $this->makeOwner();
        $this->usage($owner->currentTeam);

        Livewire::actingAs($owner)->test(UsageDashboard::class)
            ->call('analyzeSpend')
            ->assertSet('aiUnavailable', false)
            ->assertSee('Distinctive real insight about storage growth.')
            ->assertSee('Written by an AI model from this team');
    }

    public function test_a_failed_call_clears_earlier_insights_instead_of_leaving_them_up(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key-123']);
        $owner = $this->makeOwner();
        $this->usage($owner->currentTeam);
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($this->reply('{"insights": ["First real insight, distinctive."]}'))
            ->push('boom', 500)]);

        Livewire::actingAs($owner)->test(UsageDashboard::class)
            ->call('analyzeSpend')->assertSee('First real insight, distinctive.')
            ->call('analyzeSpend')->assertSet('aiInsights', [])->assertDontSee('First real insight, distinctive.')->assertSee('AI analysis unavailable');
    }

    public function test_with_no_usage_recorded_there_is_nothing_to_analyse_and_no_call_is_made(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key-123']);
        Http::fake();
        $owner = $this->makeOwner();

        Livewire::actingAs($owner)->test(UsageDashboard::class)
            ->call('analyzeSpend')
            ->assertSee('Nothing to analyse yet')
            ->assertDontSee('AI analysis unavailable');

        Http::assertNothingSent();
    }

    public function test_a_viewer_cannot_trigger_the_ai_call_but_an_editor_can(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key-123']);
        Http::fake([self::ENDPOINT => Http::response($this->reply('{"insights": ["Real insight for the editor."]}'))]);
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $this->usage($team);

        Livewire::actingAs($this->makeMember($team, 'viewer'))->test(UsageDashboard::class)
            ->assertDontSee('AI Spend Analysis')
            ->call('analyzeSpend')
            ->assertForbidden();
        Http::assertNothingSent();

        Livewire::actingAs($this->makeMember($team, 'editor'))->test(UsageDashboard::class)
            ->assertSee('AI Spend Analysis')
            ->call('analyzeSpend')
            ->assertSee('Real insight for the editor.');
    }

    public function test_the_gate_allows_owner_admin_and_editor_but_not_viewer_or_strangers(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $admin = $this->makeMember($team, 'admin');
        $editor = $this->makeMember($team, 'editor');
        $viewer = $this->makeMember($team, 'viewer');
        $stranger = $this->makeOwner();
        $team = $team->fresh();

        $this->assertTrue(Gate::forUser($owner)->allows('run-ai-analysis', $team));
        $this->assertTrue(Gate::forUser($admin)->allows('run-ai-analysis', $team));
        $this->assertTrue(Gate::forUser($editor)->allows('run-ai-analysis', $team));
        $this->assertFalse(Gate::forUser($viewer)->allows('run-ai-analysis', $team));
        $this->assertFalse(Gate::forUser($stranger)->allows('run-ai-analysis', $team));
    }
}
