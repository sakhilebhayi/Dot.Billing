<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class EntitlementsApiTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private const FILES_SECRET = 'files-entitlement-secret-for-tests-0001';

    private const MINES_SECRET = 'mines-entitlement-secret-for-tests-0002';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        config([
            'billing.entitlements.secrets.files' => self::FILES_SECRET,
            'billing.entitlements.secrets.mines' => self::MINES_SECRET,
            'billing.entitlements.secrets.hr' => '',
            'billing.free_tier' => ['seat_limit' => 3, 'storage_gb' => 1, 'features' => []],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A signed GET exactly as a calling platform would make it: HMAC over "{timestamp}." because the body is empty. */
    private function signedGet(string $uuid, string $platform = 'files', ?string $secret = null, ?int $timestamp = null, bool $prefix = true)
    {
        $timestamp ??= now()->timestamp;
        $secret ??= self::FILES_SECRET;
        $hex = hash_hmac('sha256', $timestamp.'.', $secret);

        // A real GET has no body. (getJson() would send "[]", which is not what a calling platform signs.)
        return $this->withHeaders([
            'Accept' => 'application/json',
            'X-InfoDot-Platform' => $platform,
            'X-InfoDot-Timestamp' => (string) $timestamp,
            'X-InfoDot-Signature' => ($prefix ? 'sha256=' : '').$hex,
        ])->get('/api/entitlements/'.$uuid);
    }

    private function company(array $plan = [], array $subscription = [], ?string $name = null): array
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $uuid = $this->linkToInfoDot($team);
        $plan = $this->makePlan($plan);
        $sub = $subscription === null ? null : $this->makeSubscription($team, $plan, $subscription);

        return [$team, $uuid, $plan, $sub];
    }

    public function test_a_signed_request_returns_the_companys_plan_limits(): void
    {
        [, $uuid, $plan] = $this->company(
            ['slug' => 'meridian-business', 'name' => 'Meridian Business', 'seat_limit' => 25, 'storage_gb' => 500, 'features' => ['api_access', 'priority_support']],
            ['current_period_end' => '2026-12-01 00:00:00']
        );

        $this->signedGet($uuid)->assertOk()->assertExactJson([
            'company_uuid' => $uuid,
            'status' => 'active',
            'plan' => ['slug' => 'meridian-business', 'name' => 'Meridian Business'],
            'seat_limit' => 25,
            'storage_gb' => 500,
            'features' => ['api_access', 'priority_support'],
            'period_end' => '2026-12-01T00:00:00+00:00',
            'trial_ends_at' => null,
            'cancel_at_period_end' => false,
        ]);
    }

    public function test_a_bare_hex_signature_is_accepted_as_well_as_the_sha256_prefixed_form(): void
    {
        [, $uuid] = $this->company();

        $this->signedGet($uuid, prefix: false)->assertOk();
        $this->signedGet($uuid, prefix: true)->assertOk();
    }

    public function test_unauthenticated_and_badly_signed_requests_all_get_the_same_generic_401(): void
    {
        [, $uuid] = $this->company();
        $unknown = '99999999-9999-4999-8999-999999999999';

        $bodies = collect([
            $this->withHeaders(['Accept' => 'application/json'])->get('/api/entitlements/'.$uuid),
            $this->signedGet($uuid, secret: 'wrong-secret'),
            $this->signedGet($uuid, timestamp: now()->timestamp - 301),
            $this->signedGet($uuid, timestamp: now()->timestamp + 301),
            $this->signedGet($uuid, platform: 'nonexistent'),
            $this->signedGet($uuid, platform: 'hr', secret: ''),
            $this->signedGet($unknown, secret: 'wrong-secret'),
        ])->map(function ($response) {
            $response->assertStatus(401);

            return $response->getContent();
        });

        $this->assertCount(1, $bodies->unique(), 'every failure looks identical');
        $this->assertSame(['error' => 'invalid_request'], json_decode($bodies->first(), true));
    }

    public function test_a_timestamp_inside_the_five_minute_window_is_accepted(): void
    {
        [, $uuid] = $this->company();

        $this->signedGet($uuid, timestamp: now()->timestamp - 299)->assertOk();
        $this->signedGet($uuid, timestamp: now()->timestamp + 299)->assertOk();
    }

    public function test_one_platforms_secret_cannot_sign_for_another_platform(): void
    {
        [, $uuid] = $this->company();

        $this->signedGet($uuid, platform: 'mines', secret: self::FILES_SECRET)->assertStatus(401);
        $this->signedGet($uuid, platform: 'mines', secret: self::MINES_SECRET)->assertOk();
    }

    public function test_a_platform_with_a_blank_secret_cannot_call_even_with_a_blank_signature_key(): void
    {
        [, $uuid] = $this->company();

        $this->signedGet($uuid, platform: 'hr', secret: '')->assertStatus(401);
    }

    public function test_an_unknown_company_is_a_generic_404_only_after_authentication(): void
    {
        $this->company();

        $this->signedGet('99999999-9999-4999-8999-999999999999')->assertNotFound()->assertExactJson(['error' => 'not_found']);
        $this->signedGet('not-a-uuid')->assertNotFound()->assertExactJson(['error' => 'not_found']);
    }

    public function test_a_company_only_ever_sees_its_own_plan_and_never_anothers(): void
    {
        [, $uuidA] = $this->company(['slug' => 'plan-alpha-isolated', 'name' => 'Alpha Isolated Plan', 'seat_limit' => 7, 'storage_gb' => 70, 'features' => ['alpha_feature_only']], ['status' => 'active']);
        [, $uuidB] = $this->company(['slug' => 'plan-bravo-isolated', 'name' => 'Bravo Isolated Plan', 'seat_limit' => 9, 'storage_gb' => 90, 'features' => ['bravo_feature_only']], ['status' => 'trialing', 'trial_ends_at' => '2026-11-12 00:00:00']);

        $a = $this->signedGet($uuidA)->assertOk();
        $b = $this->signedGet($uuidB)->assertOk();

        $this->assertSame('plan-alpha-isolated', $a->json('plan.slug'));
        $this->assertSame('plan-bravo-isolated', $b->json('plan.slug'));
        $this->assertStringNotContainsString('bravo', $a->getContent());
        $this->assertStringNotContainsString('alpha', $b->getContent());
        $this->assertSame('trialing', $b->json('status'));
        $this->assertSame(['alpha_feature_only'], $a->json('features'));
    }

    public function test_the_response_does_not_depend_on_who_is_signed_in_to_the_app(): void
    {
        [, $uuidA] = $this->company(['slug' => 'plan-for-a-company'], []);
        $stranger = $this->makeOwner();

        $this->actingAs($stranger);
        $this->signedGet($uuidA)->assertOk()->assertJsonPath('plan.slug', 'plan-for-a-company');
    }

    public function test_a_company_with_no_subscription_gets_status_none_and_the_free_tier_limits_from_config(): void
    {
        config(['billing.free_tier' => ['seat_limit' => 2, 'storage_gb' => 5, 'features' => ['basic_access']]]);
        $owner = $this->makeOwner();
        $uuid = $this->linkToInfoDot($owner->currentTeam);

        $this->signedGet($uuid)->assertOk()->assertExactJson([
            'company_uuid' => $uuid,
            'status' => 'none',
            'plan' => null,
            'seat_limit' => 2,
            'storage_gb' => 5,
            'features' => ['basic_access'],
            'period_end' => null,
            'trial_ends_at' => null,
            'cancel_at_period_end' => false,
        ]);
    }

    public function test_an_ended_subscription_reports_canceled_with_the_free_tier_not_the_old_plan(): void
    {
        [, $uuid] = $this->company(['slug' => 'former-plan-slug', 'seat_limit' => 99], ['status' => 'canceled', 'canceled_at' => '2026-10-01 00:00:00', 'ended_at' => '2026-10-01 00:00:00']);

        $response = $this->signedGet($uuid)->assertOk();

        $this->assertSame('canceled', $response->json('status'));
        $this->assertSame(3, $response->json('seat_limit'), 'back to the free tier');
        $this->assertNull($response->json('plan'));
        $this->assertSame('2026-10-01T00:00:00+00:00', $response->json('period_end'));
    }

    public function test_past_due_keeps_the_plan_limits_so_the_other_app_can_decide_what_to_do(): void
    {
        [, $uuid] = $this->company(['seat_limit' => 40], ['status' => 'past_due']);

        $response = $this->signedGet($uuid)->assertOk();

        $this->assertSame('past_due', $response->json('status'));
        $this->assertSame(40, $response->json('seat_limit'));
    }

    public function test_a_subscription_scheduled_to_cancel_is_still_active_and_says_so(): void
    {
        [, $uuid] = $this->company([], ['cancel_at_period_end' => true]);

        $response = $this->signedGet($uuid)->assertOk();

        $this->assertSame('active', $response->json('status'));
        $this->assertTrue($response->json('cancel_at_period_end'));
    }

    public function test_unlimited_limits_are_null(): void
    {
        [, $uuid] = $this->company(['seat_limit' => null, 'storage_gb' => null], []);

        $response = $this->signedGet($uuid)->assertOk();

        $this->assertNull($response->json('seat_limit'));
        $this->assertNull($response->json('storage_gb'));
    }

    public function test_only_the_live_subscription_counts_when_a_team_has_history(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $uuid = $this->linkToInfoDot($team);
        $this->makeSubscription($team, $this->makePlan(['slug' => 'old-history-plan']), ['status' => 'canceled', 'ended_at' => now()->subYear()]);
        $this->makeSubscription($team, $this->makePlan(['slug' => 'current-live-plan']), ['status' => 'active']);

        $this->signedGet($uuid)->assertOk()->assertJsonPath('plan.slug', 'current-live-plan');
    }

    public function test_it_supports_etag_revalidation(): void
    {
        [, $uuid] = $this->company();

        $first = $this->signedGet($uuid)->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $timestamp = now()->timestamp;
        $this->withHeaders([
            'Accept' => 'application/json',
            'X-InfoDot-Platform' => 'files',
            'X-InfoDot-Timestamp' => (string) $timestamp,
            'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.', self::FILES_SECRET),
            'If-None-Match' => $etag,
        ])->get('/api/entitlements/'.$uuid)->assertStatus(304);
    }

    public function test_a_request_that_carries_a_body_must_sign_that_body(): void
    {
        [, $uuid] = $this->company();
        $timestamp = now()->timestamp;
        $headers = ['X-InfoDot-Platform' => 'files', 'X-InfoDot-Timestamp' => (string) $timestamp];

        // Signed for an empty body but sent with one: the body cannot be swapped under a signature.
        $this->call('GET', '/api/entitlements/'.$uuid, [], [], [], $this->transformHeadersToServerVars($headers + [
            'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.', self::FILES_SECRET),
        ]), 'tampered-body')->assertStatus(401);

        $this->call('GET', '/api/entitlements/'.$uuid, [], [], [], $this->transformHeadersToServerVars($headers + [
            'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.tampered-body', self::FILES_SECRET),
        ]), 'tampered-body')->assertOk();
    }

    public function test_the_secret_config_is_built_from_the_platform_registry_and_blank_by_default(): void
    {
        $secrets = (require base_path('config/billing.php'))['entitlements']['secrets'];

        $this->assertArrayHasKey('files', $secrets);
        $this->assertArrayHasKey('mines', $secrets);
        $this->assertSame('', $secrets['files']);
    }
}
