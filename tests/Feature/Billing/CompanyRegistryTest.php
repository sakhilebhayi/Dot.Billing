<?php

namespace Tests\Feature\Billing;

use App\Livewire\Billing\DunningQueue;
use App\Models\BillingAuditEvent;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSubscription;
use App\Models\DunningCase;
use App\Models\InfodotCompany;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class CompanyRegistryTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private const INFODOT_SECRET = 'infodot-entitlement-secret-for-tests-0001';

    private const FILES_SECRET = 'files-entitlement-secret-for-tests-0001';

    private const UUID = '3f1c2a64-8d0b-4c7e-9a52-1b6e4f7d9c01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        config([
            'billing.entitlements.secrets.infodot' => self::INFODOT_SECRET,
            'billing.entitlements.secrets.files' => self::FILES_SECRET,
            'billing.free_tier' => ['seat_limit' => 3, 'storage_gb' => 1, 'features' => []],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A call exactly as InfoDot would make it: HMAC-SHA256 over "{timestamp}.{raw body}". */
    private function register(array|string $body, string $platform = 'infodot', ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $raw = is_string($body) ? $body : json_encode($body);
        $timestamp ??= now()->timestamp;
        $secret ??= self::INFODOT_SECRET;

        return $this->call('POST', '/api/companies', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_PLATFORM' => $platform,
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret),
        ], $raw);
    }

    private function signedGet(string $uuid): TestResponse
    {
        $timestamp = now()->timestamp;

        return $this->withHeaders([
            'Accept' => 'application/json',
            'X-InfoDot-Platform' => 'files',
            'X-InfoDot-Timestamp' => (string) $timestamp,
            'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.', self::FILES_SECRET),
        ])->get('/api/entitlements/'.$uuid);
    }

    private function body(array $over = []): array
    {
        return array_merge(['company_uuid' => self::UUID, 'name' => 'Meridian Holdings'], $over);
    }

    /** @return array{0: Team, 1: string} */
    private function companyWithBilling(): array
    {
        $team = $this->makeCompany(['name' => 'Old Name']);
        $plan = $this->makePlan();
        $sub = $this->makeSubscription($team, $plan);
        $invoice = $this->makeInvoice($team);
        BillingPayment::create(['team_id' => $team->id, 'invoice_id' => $invoice->id, 'amount_cents' => 1999, 'currency' => 'ZAR', 'status' => 'succeeded']);

        return [$team, $team->infodot_team_uuid];
    }

    /** @return array<string, int> */
    private function billingCounts(): array
    {
        return [
            'subscriptions' => BillingSubscription::withoutGlobalScopes()->count(),
            'invoices' => BillingInvoice::withoutGlobalScopes()->count(),
            'payments' => BillingPayment::withoutGlobalScopes()->count(),
        ];
    }

    private function auditCount(): int
    {
        return BillingAuditEvent::withoutGlobalScopes()->where('action', 'like', 'company.%')->count();
    }

    // ---- authentication ----

    public function test_a_bad_signature_is_a_generic_401_and_nothing_is_written(): void
    {
        $this->register($this->body(), secret: 'wrong-secret')->assertStatus(401)->assertExactJson(['error' => 'invalid_request']);

        $this->assertSame(0, InfodotCompany::count());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_a_stale_or_future_timestamp_is_a_401(): void
    {
        $this->register($this->body(), timestamp: now()->timestamp - 301)->assertStatus(401);
        $this->register($this->body(), timestamp: now()->timestamp + 301)->assertStatus(401);
        $this->assertSame(0, InfodotCompany::count());
    }

    public function test_only_the_infodot_platform_may_call_even_with_its_own_valid_secret(): void
    {
        $this->register($this->body(), platform: 'files', secret: self::FILES_SECRET)->assertStatus(401)->assertExactJson(['error' => 'invalid_request']);
        $this->assertSame(0, InfodotCompany::count());
    }

    public function test_a_blank_secret_for_infodot_cannot_call(): void
    {
        config(['billing.entitlements.secrets.infodot' => '']);

        $this->register($this->body(), secret: '')->assertStatus(401);
        $this->assertSame(0, InfodotCompany::count());
    }

    public function test_a_signature_over_a_different_body_is_a_401(): void
    {
        $timestamp = now()->timestamp;
        $signedFor = json_encode($this->body(['name' => 'Signed Name']));

        $this->call('POST', '/api/companies', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_PLATFORM' => 'infodot',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$signedFor, self::INFODOT_SECRET),
        ], json_encode($this->body(['name' => 'Tampered Name'])))->assertStatus(401);

        $this->assertSame(0, InfodotCompany::count());
    }

    public function test_authentication_comes_before_validation_and_lookup(): void
    {
        // A bad body with a bad signature is a 401, not a 422: nothing about the body is revealed.
        $this->register(['company_uuid' => 'nope'], secret: 'wrong-secret')->assertStatus(401);
        // The same answer whether or not the company exists.
        [, $known] = $this->companyWithBilling();
        $unknown = $this->register($this->body(), secret: 'wrong-secret');
        $existing = $this->register($this->body(['company_uuid' => $known]), secret: 'wrong-secret');
        $this->assertSame($unknown->getContent(), $existing->getContent());
    }

    // ---- validation ----

    public function test_a_bad_body_is_a_422(): void
    {
        $this->register([])->assertStatus(422)->assertJsonValidationErrors(['company_uuid', 'name']);
        $this->register($this->body(['company_uuid' => 'not-a-uuid']))->assertStatus(422)->assertJsonValidationErrors(['company_uuid']);
        $this->register($this->body(['name' => str_repeat('x', 121)]))->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->register($this->body(['name' => '']))->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->register($this->body(['name' => ['a']]))->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->register($this->body(['deleted' => 'maybe']))->assertStatus(422)->assertJsonValidationErrors(['deleted']);
        $this->register('{not json')->assertStatus(422);

        $this->assertSame(0, InfodotCompany::count());
    }

    public function test_a_name_of_exactly_120_characters_is_accepted(): void
    {
        $this->register($this->body(['name' => str_repeat('x', 120)]))->assertOk()->assertExactJson(['status' => 'registered']);
    }

    // ---- registering ----

    public function test_a_new_company_is_registered_without_creating_a_team_or_a_user(): void
    {
        $teams = Team::count();
        $users = User::count();

        $this->register($this->body(['company_uuid' => strtoupper(self::UUID)]))->assertOk()->assertExactJson(['status' => 'registered']);

        $row = InfodotCompany::sole();
        $this->assertSame(self::UUID, $row->company_uuid, 'the uuid is stored lower case');
        $this->assertSame('Meridian Holdings', $row->name);
        $this->assertSame(now()->toDateTimeString(), $row->registered_at->toDateTimeString());
        $this->assertNull($row->deleted_at);
        $this->assertSame($teams, Team::count());
        $this->assertSame($users, User::count());
    }

    public function test_registering_writes_one_audit_entry_without_a_team(): void
    {
        $this->register($this->body())->assertOk();

        $event = BillingAuditEvent::withoutGlobalScopes()->sole();
        $this->assertSame('company.registered', $event->action);
        $this->assertNull($event->team_id);
        $this->assertSame(self::UUID, $event->meta['company_uuid']);
    }

    public function test_the_same_body_twice_changes_nothing_and_writes_no_second_audit_entry(): void
    {
        $this->register($this->body())->assertOk()->assertExactJson(['status' => 'registered']);
        $before = InfodotCompany::sole()->only(['id', 'name', 'registered_at', 'deleted_at', 'updated_at']);
        $audits = $this->auditCount();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->register($this->body())->assertOk()->assertExactJson(['status' => 'unchanged']);

        $this->assertSame(1, InfodotCompany::count());
        $this->assertEquals($before, InfodotCompany::sole()->only(['id', 'name', 'registered_at', 'deleted_at', 'updated_at']));
        $this->assertSame($audits, $this->auditCount());
    }

    public function test_a_deleted_body_repeated_is_unchanged_and_keeps_the_first_deletion_time(): void
    {
        $this->register($this->body(['deleted' => true]))->assertOk()->assertExactJson(['status' => 'deleted']);
        $first = InfodotCompany::sole()->deleted_at;
        $audits = $this->auditCount();

        Carbon::setTestNow('2026-11-06 09:00:00');
        $this->register($this->body(['deleted' => true]))->assertOk()->assertExactJson(['status' => 'unchanged']);

        $this->assertEquals($first, InfodotCompany::sole()->deleted_at);
        $this->assertSame($audits, $this->auditCount());
    }

    public function test_a_rename_of_a_registered_company_is_updated(): void
    {
        $this->register($this->body())->assertOk();
        $this->register($this->body(['name' => 'Meridian Group']))->assertOk()->assertExactJson(['status' => 'updated']);

        $this->assertSame('Meridian Group', InfodotCompany::sole()->name);
        $rename = BillingAuditEvent::withoutGlobalScopes()->where('action', 'company.renamed')->sole();
        $this->assertSame('Meridian Holdings', $rename->meta['previous_name']);
    }

    // ---- teams ----

    public function test_a_rename_updates_the_team(): void
    {
        [$team, $uuid] = $this->companyWithBilling();

        $this->register($this->body(['company_uuid' => $uuid, 'name' => 'New Name']))->assertOk()->assertExactJson(['status' => 'registered']);

        $this->assertSame('New Name', $team->fresh()->name);
        $this->assertSame(1, Team::where('infodot_team_uuid', $uuid)->count());

        $event = BillingAuditEvent::withoutGlobalScopes()->where('action', 'company.renamed')->sole();
        $this->assertSame($team->id, $event->team_id);
        $this->assertSame('Old Name', $event->meta['previous_name']);

        // Again: nothing more to do.
        $audits = $this->auditCount();
        $this->register($this->body(['company_uuid' => $uuid, 'name' => 'New Name']))->assertOk()->assertExactJson(['status' => 'unchanged']);
        $this->assertSame($audits, $this->auditCount());
    }

    public function test_a_team_that_is_out_of_step_with_an_up_to_date_registry_row_is_still_renamed(): void
    {
        InfodotCompany::create(['company_uuid' => self::UUID, 'name' => 'Meridian Holdings', 'registered_at' => now()]);
        $team = $this->makeCompany(['name' => 'Stale']);
        $this->linkToInfoDot($team, self::UUID);

        $this->register($this->body())->assertOk()->assertExactJson(['status' => 'updated']);

        $this->assertSame('Meridian Holdings', $team->fresh()->name);
    }

    public function test_an_unrelated_team_is_not_touched(): void
    {
        [$team] = $this->companyWithBilling();
        $other = $this->makeCompany(['name' => 'Bystander']);

        $this->register($this->body(['company_uuid' => $team->infodot_team_uuid, 'deleted' => true]))->assertOk();

        $this->assertSame('Bystander', $other->fresh()->name);
        $this->assertNull($other->fresh()->infodot_deleted_at);
    }

    public function test_a_deletion_flags_the_team_and_never_touches_subscriptions_invoices_or_payments(): void
    {
        [$team, $uuid] = $this->companyWithBilling();
        $counts = $this->billingCounts();
        $sub = BillingSubscription::withoutGlobalScopes()->where('team_id', $team->id)->sole();
        $subBefore = $sub->getAttributes();
        $invoiceBefore = BillingInvoice::withoutGlobalScopes()->where('team_id', $team->id)->sole()->getAttributes();
        $paymentBefore = BillingPayment::withoutGlobalScopes()->where('team_id', $team->id)->sole()->getAttributes();

        $this->register($this->body(['company_uuid' => $uuid, 'name' => 'Old Name', 'deleted' => true]))->assertOk()->assertExactJson(['status' => 'deleted']);

        $this->assertSame($counts, $this->billingCounts());
        $this->assertEquals($subBefore, BillingSubscription::withoutGlobalScopes()->find($sub->id)->getAttributes());
        $this->assertEquals($invoiceBefore, BillingInvoice::withoutGlobalScopes()->where('team_id', $team->id)->sole()->getAttributes());
        $this->assertEquals($paymentBefore, BillingPayment::withoutGlobalScopes()->where('team_id', $team->id)->sole()->getAttributes());
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertNull($sub->fresh()->canceled_at);
        $this->assertNull($sub->fresh()->ended_at);

        $this->assertNotNull($team->fresh()->infodot_deleted_at);
        $this->assertTrue($team->fresh()->deletedInInfoDot());
        $this->assertNotNull(InfodotCompany::sole()->deleted_at);

        $event = BillingAuditEvent::withoutGlobalScopes()->where('action', 'company.deleted')->sole();
        $this->assertSame($team->id, $event->team_id);
    }

    public function test_the_entitlements_of_a_team_flagged_deleted_are_unchanged(): void
    {
        [, $uuid] = $this->companyWithBilling();
        $before = $this->signedGet($uuid)->assertOk()->json();

        $this->register($this->body(['company_uuid' => $uuid, 'name' => 'Old Name', 'deleted' => true]))->assertOk();

        $this->assertSame($before, $this->signedGet($uuid)->assertOk()->json());
    }

    public function test_an_undelete_clears_the_flag_and_leaves_billing_alone(): void
    {
        [$team, $uuid] = $this->companyWithBilling();
        $this->register($this->body(['company_uuid' => $uuid, 'name' => 'Old Name', 'deleted' => true]))->assertOk();
        $counts = $this->billingCounts();

        // deleted absent
        $this->register($this->body(['company_uuid' => $uuid, 'name' => 'Old Name']))->assertOk()->assertExactJson(['status' => 'updated']);

        $this->assertNull($team->fresh()->infodot_deleted_at);
        $this->assertNull(InfodotCompany::sole()->deleted_at);
        $this->assertSame($counts, $this->billingCounts());
        $this->assertSame(1, BillingAuditEvent::withoutGlobalScopes()->where('action', 'company.restored')->count());

        // delete again, then un-delete with an explicit false
        $this->register($this->body(['company_uuid' => $uuid, 'name' => 'Old Name', 'deleted' => true]))->assertOk()->assertExactJson(['status' => 'deleted']);
        $this->register($this->body(['company_uuid' => $uuid, 'name' => 'Old Name', 'deleted' => false]))->assertOk()->assertExactJson(['status' => 'updated']);
        $this->assertNull($team->fresh()->infodot_deleted_at);
    }

    public function test_a_company_first_seen_as_deleted_is_registered_as_deleted(): void
    {
        $this->register($this->body(['deleted' => true]))->assertOk()->assertExactJson(['status' => 'deleted']);

        $this->assertNotNull(InfodotCompany::sole()->deleted_at);
        $this->assertSame(['company.registered', 'company.deleted'], BillingAuditEvent::withoutGlobalScopes()->orderBy('id')->pluck('action')->all());
    }

    // ---- entitlements fallback ----

    public function test_a_registered_company_with_no_team_answers_as_never_subscribed(): void
    {
        $this->register($this->body())->assertOk();
        $teams = Team::count();

        $this->signedGet(self::UUID)->assertOk()->assertExactJson([
            'company_uuid' => self::UUID,
            'status' => 'none',
            'plan' => null,
            'seat_limit' => 3,
            'extra_seats' => 0,
            'storage_gb' => 1,
            'features' => [],
            'period_end' => null,
            'trial_ends_at' => null,
            'cancel_at_period_end' => false,
        ]);

        $this->assertSame($teams, Team::count(), 'answering does not create a team');
    }

    public function test_the_fallback_has_the_same_shape_as_a_team_that_never_subscribed(): void
    {
        $team = $this->makeCompany();
        $this->register($this->body())->assertOk();

        $never = $this->signedGet($team->infodot_team_uuid)->assertOk()->json();
        $registered = $this->signedGet(self::UUID)->assertOk()->json();

        $this->assertSame(array_keys($never), array_keys($registered));
        unset($never['company_uuid'], $registered['company_uuid']);
        $this->assertSame($never, $registered);
    }

    public function test_the_fallback_supports_etags(): void
    {
        $this->register($this->body())->assertOk();
        $etag = $this->signedGet(self::UUID)->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $timestamp = now()->timestamp;
        $this->withHeaders([
            'Accept' => 'application/json',
            'X-InfoDot-Platform' => 'files',
            'X-InfoDot-Timestamp' => (string) $timestamp,
            'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.', self::FILES_SECRET),
            'If-None-Match' => $etag,
        ])->get('/api/entitlements/'.self::UUID)->assertStatus(304);
    }

    public function test_the_fallback_uses_the_free_tier_configuration(): void
    {
        config(['billing.free_tier' => ['seat_limit' => 7, 'storage_gb' => 9, 'features' => ['a']]]);
        $this->register($this->body())->assertOk();

        $this->signedGet(self::UUID)->assertOk()->assertJson(['seat_limit' => 7, 'storage_gb' => 9, 'features' => ['a']]);
    }

    public function test_a_uuid_unknown_to_both_is_still_404(): void
    {
        $this->register($this->body())->assertOk();

        $this->signedGet('99999999-9999-4999-8999-999999999999')->assertNotFound()->assertExactJson(['error' => 'not_found']);
    }

    public function test_a_deleted_registry_company_with_no_team_is_404(): void
    {
        $this->register($this->body())->assertOk();
        $this->signedGet(self::UUID)->assertOk();

        $this->register($this->body(['deleted' => true]))->assertOk();
        $this->signedGet(self::UUID)->assertNotFound()->assertExactJson(['error' => 'not_found']);

        $this->register($this->body())->assertOk()->assertExactJson(['status' => 'updated']);
        $this->signedGet(self::UUID)->assertOk()->assertJson(['status' => 'none']);
    }

    public function test_the_fallback_does_not_weaken_entitlement_authentication(): void
    {
        $this->register($this->body())->assertOk();

        $this->withHeaders(['Accept' => 'application/json', 'X-InfoDot-Platform' => 'files', 'X-InfoDot-Timestamp' => (string) now()->timestamp, 'X-InfoDot-Signature' => 'sha256=bad'])
            ->get('/api/entitlements/'.self::UUID)->assertStatus(401);
    }

    // ---- migration ----

    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $migration = require database_path('migrations/2026_10_29_140000_create_infodot_companies_registry.php');
        InfodotCompany::create(['company_uuid' => self::UUID, 'name' => 'Keep Me', 'registered_at' => now()]);

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('infodot_companies'));
        $this->assertTrue(Schema::hasColumn('teams', 'infodot_deleted_at'));
        $this->assertSame('Keep Me', InfodotCompany::sole()->name);
    }

    public function test_the_company_uuid_is_unique(): void
    {
        InfodotCompany::create(['company_uuid' => self::UUID, 'name' => 'A', 'registered_at' => now()]);

        $this->expectException(UniqueConstraintViolationException::class);
        InfodotCompany::create(['company_uuid' => self::UUID, 'name' => 'B', 'registered_at' => now()]);
    }

    // ---- operator badge ----

    public function test_the_operator_lookup_shows_the_deleted_badge_and_the_registry_note(): void
    {
        [, $uuid] = $this->companyWithBilling();
        $this->register($this->body(['company_uuid' => $uuid, 'name' => 'Old Name', 'deleted' => true]))->assertOk();
        $this->register($this->body())->assertOk();
        $operator = $this->makeOperator();

        $this->actingAs($operator)->get(route('operator.entitlements.index', ['company' => $uuid]))
            ->assertOk()->assertSee('Deleted in InfoDot');

        $this->actingAs($operator)->get(route('operator.entitlements.index', ['company' => self::UUID]))
            ->assertOk()->assertSee('InfoDot has registered it')->assertSee('Meridian Holdings');
    }

    public function test_the_lookup_shows_no_badge_for_a_team_that_is_not_deleted(): void
    {
        [, $uuid] = $this->companyWithBilling();

        $this->actingAs($this->makeOperator())->get(route('operator.entitlements.index', ['company' => $uuid]))
            ->assertOk()->assertDontSee('Deleted in InfoDot');
    }

    public function test_the_dunning_queue_marks_a_team_deleted_in_infodot(): void
    {
        $team = $this->makeCompany(['name' => 'Gone Co']);
        $sub = $this->makeSubscription($team, $this->makePlan(), ['status' => 'past_due']);
        $invoice = $this->makeInvoice($team, ['subscription_id' => $sub->id, 'due_date' => now()->subDays(5)]);
        DunningCase::create(['team_id' => $team->id, 'invoice_id' => $invoice->id, 'reason' => 'invoice_overdue', 'status' => 'open']);
        $this->actingAs($this->makeOperator());

        Livewire::test(DunningQueue::class)->assertSee('Gone Co')->assertDontSee('Deleted in InfoDot');

        $this->register($this->body(['company_uuid' => $team->infodot_team_uuid, 'name' => 'Gone Co', 'deleted' => true]))->assertOk();

        Livewire::test(DunningQueue::class)->assertSee('Deleted in InfoDot');
        $this->assertSame('past_due', $sub->fresh()->status);
    }
}
