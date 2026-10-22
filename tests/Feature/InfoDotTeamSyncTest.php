<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signing in through InfoDot applies the companies InfoDot lists for the person:
 * creation by owners and admins only, membership and role following InfoDot,
 * revocation of companies InfoDot no longer lists, and the active company.
 */
class InfoDotTeamSyncTest extends TestCase
{
    use RefreshDatabase;

    /** InfoDot's launch page, as the browser reports it. */
    private function fromInfodot(): array
    {
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return ['Origin' => 'https://'.$infodot];
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return ['Origin' => 'https://'.(str_contains($host, '.') ? substr($host, strpos($host, '.') + 1) : $host)];
    }

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL, 'platform' => 'billing', 'secret' => 'secret-for-tests', 'timeout' => 2, 'provision' => true,
        ]]);
    }

    private function signIn(string $email, array $teams, ?string $current = null): void
    {
        // A fresh client each time: Http::fake() keeps the FIRST stub for a URL.
        Http::swap(new Factory);
        Http::fake([self::URL => Http::response([
            'user' => ['id' => 1, 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth('web')->logout();
        $this->post(route('ecosystem.auth'), ['token' => 'one-time'], $this->fromInfodot())->assertRedirect();
    }

    private function company(string $uuid, string $name, string $role, bool $personal = false): array
    {
        return ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role];
    }

    public function test_an_owner_creates_the_company_and_it_becomes_their_active_team(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertSame('Acme Ltd', $team->name);
        $this->assertFalse($team->personal_team);
        $this->assertSame($user->id, $team->user_id);
        $this->assertSame($team->id, $user->fresh()->current_team_id);
    }

    public function test_an_ordinary_member_cannot_create_a_company_but_is_attached_once_it_exists(): void
    {
        $this->signIn('bob@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'editor')], self::ACME);
        $this->assertNull(Team::where('infodot_team_uuid', self::ACME)->first(), 'a member must not become the local owner');

        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $this->signIn('bob@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'editor')], self::ACME);

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $bob = User::where('email', 'bob@acme.test')->firstOrFail();

        $this->assertTrue($bob->belongsToTeam($team));
        $this->assertSame('editor', $bob->teamRole($team)->key);
        $this->assertSame(1, Team::where('infodot_team_uuid', self::ACME)->count());
        $this->assertSame($team->id, $bob->fresh()->current_team_id);
    }

    public function test_roles_follow_infodot_and_admin_maps_to_admin(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $this->signIn('carl@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'editor')], self::ACME);

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $carl = User::where('email', 'carl@acme.test')->firstOrFail();
        $this->assertSame('editor', $carl->teamRole($team)->key);

        $this->signIn('carl@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'admin')], self::ACME);
        $this->assertSame('admin', $carl->fresh()->teamRole($team->fresh())->key);

        $this->signIn('carl@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'editor')], self::ACME);
        $this->assertSame('editor', $carl->fresh()->teamRole($team->fresh())->key, 'a demotion in InfoDot is applied too');
    }

    public function test_a_person_removed_in_infodot_loses_the_company_here_at_next_sign_in(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $this->signIn('bob@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'editor')], self::ACME);
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $bob = User::where('email', 'bob@acme.test')->firstOrFail();
        $this->assertTrue($bob->belongsToTeam($team));
        $this->assertSame($team->id, $bob->fresh()->current_team_id);

        $this->signIn('bob@acme.test', [], null);

        $this->assertFalse($bob->fresh()->belongsToTeam($team));
        $this->assertNotSame($team->id, $bob->fresh()->current_team_id);
        $this->assertSame($bob->fresh()->personalTeam()->id, $bob->fresh()->current_team_id);
    }

    public function test_a_person_in_two_companies_gets_both_and_the_active_one_is_current(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);
        $this->signIn('gina@globex.test', [$this->company(self::GLOBEX, 'Globex Corp', 'owner')], self::GLOBEX);
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();

        $both = [$this->company(self::ACME, 'Acme Ltd', 'editor'), $this->company(self::GLOBEX, 'Globex Corp', 'editor')];
        $this->signIn('dan@both.test', $both, self::GLOBEX);
        $dan = User::where('email', 'dan@both.test')->firstOrFail();

        $this->assertTrue($dan->belongsToTeam($acme));
        $this->assertTrue($dan->belongsToTeam($globex));
        $this->assertSame($globex->id, $dan->fresh()->current_team_id);

        $this->signIn('dan@both.test', $both, self::ACME);
        $this->assertSame($acme->id, $dan->fresh()->current_team_id);
    }

    public function test_the_current_team_is_never_one_the_person_does_not_belong_to(): void
    {
        $this->signIn('gina@globex.test', [$this->company(self::GLOBEX, 'Globex Corp', 'owner')], self::GLOBEX);
        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();

        // InfoDot claims Eve is acting for Globex but lists no membership of it.
        $this->signIn('eve@else.test', [], self::GLOBEX);
        $eve = User::where('email', 'eve@else.test')->firstOrFail();

        $this->assertFalse($eve->belongsToTeam($globex));
        $this->assertNotSame($globex->id, $eve->fresh()->current_team_id);
    }

    public function test_personal_teams_in_the_payload_are_never_linked(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Ann personal', 'owner', true)], self::ACME);

        $this->assertSame(0, Team::whereNotNull('infodot_team_uuid')->count());
    }

    public function test_link_team_command_attaches_an_existing_team_and_refuses_bad_links(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $existing = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Existing', 'personal_team' => false]);

        $this->artisan('infodot:link-team', ['team' => $existing->id, 'uuid' => self::ACME])->assertSuccessful();
        $this->assertSame(self::ACME, $existing->fresh()->infodot_team_uuid);

        $this->artisan('infodot:link-team', ['team' => $existing->id, 'uuid' => self::GLOBEX])->assertFailed();
        $this->artisan('infodot:link-team', ['team' => $owner->currentTeam->id, 'uuid' => self::GLOBEX])->assertFailed();
        $this->assertNull($owner->currentTeam->fresh()->infodot_team_uuid, 'a personal team is refused without --promote');
        $this->artisan('infodot:link-team', ['team' => $existing->id, 'uuid' => 'not-a-uuid'])->assertFailed();

        $other = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Other', 'personal_team' => false]);
        $this->artisan('infodot:link-team', ['team' => $other->id, 'uuid' => self::ACME])->assertFailed();
    }

    public function test_promote_turns_a_personal_team_into_a_linked_company_and_keeps_its_data(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $personal = $owner->currentTeam;
        $this->assertTrue($personal->personal_team);

        $this->artisan('infodot:link-team', ['team' => $personal->id, 'uuid' => self::ACME, '--promote' => true])->assertSuccessful();

        $fresh = $personal->fresh();
        $this->assertFalse($fresh->personal_team);
        $this->assertSame(self::ACME, $fresh->infodot_team_uuid);
        $this->assertSame($owner->id, $fresh->user_id);
        $this->assertTrue($owner->fresh()->belongsToTeam($fresh));
    }

    public function test_after_promotion_the_next_sign_in_reuses_the_team_instead_of_creating_another(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);
        $this->artisan('infodot:link-team', ['team' => $owner->currentTeam->id, 'uuid' => self::ACME, '--promote' => true])->assertSuccessful();
        $before = Team::count();

        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME);

        $this->assertSame($before, Team::count());
        $this->assertSame($owner->currentTeam->id, $owner->fresh()->current_team_id);
    }
}
