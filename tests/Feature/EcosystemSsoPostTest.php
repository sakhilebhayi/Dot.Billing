<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The sign-in token from InfoDot arrives in a POST body and is never in a URL; a refused sign-in lands on the
 * plain login page with nothing echoed.
 */
class EcosystemSsoPostTest extends TestCase
{
    use RefreshDatabase;

    /** InfoDot's launch page, as the browser reports it: InfoDot's host when this app is told it, else this app's parent domain. */
    private function fromInfodot(): array
    {
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return ['Origin' => 'https://'.$infodot];
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $parent = str_contains($host, '.') ? substr($host, strpos($host, '.') + 1) : $host;

        return ['Origin' => 'https://'.$parent];
    }

    /**
     * A handoff token as InfoDot mints it. Written with the query builder because some platforms still share
     * InfoDot's older token table, which may have no expires_at column.
     */
    private function token(User $user, bool $expired = false): string
    {
        $plain = Str::random(40);
        $row = [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->getKey(),
            'name' => 'ecosystem-handoff',
            'token' => hash('sha256', $plain),
            'abilities' => json_encode(['ecosystem:read']),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            $row['expires_at'] = $expired ? now()->subMinute() : now()->addMinute();
        }

        return DB::table('personal_access_tokens')->insertGetId($row).'|'.$plain;
    }

    public function test_a_post_from_infodot_signs_the_person_in_and_spends_the_token(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);

        $response = $this->post('/auth/ecosystem', ['token' => $token], $this->fromInfodot());

        $response->assertRedirect();
        $this->assertNotSame('/login', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $this->assertAuthenticatedAs($user);
        $this->assertStringNotContainsString($token, (string) $response->headers->get('Location'));
        $this->assertSame(0, DB::table('personal_access_tokens')->count(), 'the token is single use');
    }

    public function test_a_post_from_another_site_is_refused_and_the_token_is_neither_spent_nor_echoed(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);

        $response = $this->post('/auth/ecosystem', ['token' => $token], ['Origin' => 'https://evil.example']);

        $response->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
        $this->assertStringNotContainsString($token, (string) $response->headers->get('Location').$response->getContent());
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_a_post_that_names_no_origin_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post('/auth/ecosystem', ['token' => $this->token($user)])->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_bad_or_expired_token_lands_on_the_plain_login_page_with_nothing_echoed(): void
    {
        $user = User::factory()->create();
        $tokens = ['not-a-token', ''];

        if (Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            $tokens[] = $this->token($user, expired: true);
        }

        foreach ($tokens as $token) {
            $response = $this->post('/auth/ecosystem', ['token' => $token], $this->fromInfodot());

            $response->assertStatus(303)->assertRedirect('/login');
            $this->assertGuest();
            $this->assertStringNotContainsString('token', (string) $response->headers->get('Location'));
            if ($token !== '') {
                $this->assertStringNotContainsString($token, (string) $response->headers->get('Location').$response->getContent());
            }
        }
    }

    public function test_an_old_get_link_with_a_bad_token_also_ends_on_the_login_page_not_on_an_error_page_with_the_token(): void
    {
        $this->get('/auth/ecosystem?token=not-a-token')->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_post_route_carries_no_csrf_middleware_because_it_comes_from_another_origin(): void
    {
        $route = Route::getRoutes()->match(Request::create('/auth/ecosystem', 'POST'));

        foreach (app('router')->gatherRouteMiddleware($route) as $name) {
            $this->assertStringNotContainsString('CsrfToken', is_string($name) ? $name : '');
        }
    }

    public function test_when_infodot_is_known_only_its_own_origin_is_accepted_not_a_sibling_subdomain(): void
    {
        config(['ecosystem.infodot.redeem_url' => 'https://infodot.co.za/api/ecosystem/redeem']);
        $user = User::factory()->create();

        $this->post('/auth/ecosystem', ['token' => $this->token($user)], ['Origin' => 'https://evil.infodot.co.za'])
            ->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();

        $this->post('/auth/ecosystem', ['token' => $this->token($user)], ['Origin' => 'https://infodot.co.za'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }
}
