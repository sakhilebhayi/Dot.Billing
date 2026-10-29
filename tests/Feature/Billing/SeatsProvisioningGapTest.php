<?php

namespace Tests\Feature\Billing;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * How an InfoDot company becomes a Billing team, as it behaves today (documented so a change is noticed):
 * the ONLY path is an owner or admin signing in from InfoDot (SyncTeamsFromInfoDot). Until then Billing has no
 * team for the company and GET /api/entitlements/{uuid} is a 404. There is no membership webhook or provisioning call.
 */
class SeatsProvisioningGapTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const SECRET = 'infodot-entitlement-secret-for-tests-0001';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ecosystem.infodot' => ['redeem_url' => self::URL, 'platform' => 'billing', 'secret' => 'secret-for-tests', 'timeout' => 2, 'provision' => true],
            'billing.entitlements.secrets.infodot' => self::SECRET,
        ]);
    }

    private function entitlements(string $uuid)
    {
        $timestamp = now()->timestamp;

        return $this->withHeaders([
            'Accept' => 'application/json', 'X-InfoDot-Platform' => 'infodot', 'X-InfoDot-Timestamp' => (string) $timestamp,
            'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.', self::SECRET),
        ])->get('/api/entitlements/'.$uuid);
    }

    private function signIn(string $role): void
    {
        $host = (string) parse_url(self::URL, PHP_URL_HOST);
        Http::swap(new Factory);
        Http::fake([self::URL => Http::response([
            'user' => ['id' => 1, 'name' => 'Ann', 'email' => 'ann@acme.test', 'email_verified' => true],
            'teams' => [['id' => 5, 'uuid' => self::ACME, 'name' => 'Acme Ltd', 'personal' => false, 'role' => $role]],
            'current_team_id' => 5, 'current_team_uuid' => self::ACME,
        ])]);

        $this->post(route('ecosystem.auth'), ['token' => 'one-time', 'redirect' => '/seats'], ['Origin' => 'https://'.$host])->assertRedirect('/seats');
    }

    public function test_a_company_nobody_has_opened_billing_for_has_no_team_and_entitlements_is_a_404(): void
    {
        $this->assertSame(0, Team::where('infodot_team_uuid', self::ACME)->count());

        $this->entitlements(self::ACME)->assertNotFound()->assertExactJson(['error' => 'not_found']);
    }

    public function test_an_owner_arriving_from_infodot_with_a_seats_deep_link_provisions_the_company_and_lands_on_the_seats_screen(): void
    {
        $this->signIn('owner');

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $this->assertSame('Acme Ltd', $team->name);

        $this->get('/seats')->assertOk()->assertSee('No running subscription');
        $this->entitlements(self::ACME)->assertOk()->assertJson(['company_uuid' => self::ACME, 'status' => 'none', 'plan' => null]);
    }

    public function test_an_ordinary_member_cannot_provision_the_company_so_the_gap_stays_open_for_them(): void
    {
        $this->signIn('member');

        $this->assertSame(0, Team::where('infodot_team_uuid', self::ACME)->count());
        $this->entitlements(self::ACME)->assertNotFound();
    }
}
