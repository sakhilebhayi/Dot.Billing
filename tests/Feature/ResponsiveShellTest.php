<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Team;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

/**
 * Guards the markup hooks of the responsive app shell (off-canvas drawer, overflow wrappers,
 * stacking helpers). These assert structure, not pixels: the drawer behaviour itself is plain
 * CSS plus a small inline script, so what can regress silently is a hook being renamed or removed.
 */
class ResponsiveShellTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private function signedInPage(string $routeName): string
    {
        $owner = $this->makeOwner();
        $owner->forceFill(['is_platform_operator' => true])->save();
        $team = $owner->currentTeam;
        $plan = $this->makePlan();
        $subscription = $this->makeSubscription($team, $plan);
        $invoice = $this->makeInvoice($team, ['subscription_id' => $subscription->id]);
        $invoice->items()->create(['description' => 'Shell test plan line', 'quantity' => 1, 'unit_price_cents' => 50000, 'amount_cents' => 50000, 'period' => '1 Nov 2026 to 1 Dec 2026']);

        $response = $this->actingAs($owner)->get(route($routeName, $routeName === 'invoices.show' ? $invoice : []));
        $response->assertOk();

        return $response->getContent();
    }

    /** @return array<string, array{0: string}> */
    public static function shellPages(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'invoices' => ['invoices.index'],
            'invoice' => ['invoices.show'],
            'plan catalogue' => ['operator.plans.index'],
            'entitlement lookup' => ['operator.entitlements.index'],
            'dunning cases' => ['operator.dunning-cases.index'],
        ];
    }

    #[DataProvider('shellPages')]
    public function test_every_app_page_carries_the_drawer_shell(string $routeName): void
    {
        $html = $this->signedInPage($routeName);

        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1"', $html);

        // Visible, labelled menu button wired to the drawer.
        $this->assertMatchesRegularExpression('/<button[^>]*class="shell-menu"[^>]*data-shell-menu[^>]*aria-controls="app-sidebar"[^>]*aria-expanded="false"/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-shell-menu[^>]*>.*?<span>Menu<\/span>/s', $html);

        // The drawer is the existing sidebar, addressable by the id the button controls.
        $this->assertMatchesRegularExpression('/<aside id="app-sidebar" class="sidebar"[^>]*data-shell-drawer/', $html);
        $this->assertStringContainsString('data-shell-backdrop', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-shell-close[^>]*aria-label="Close menu"/', $html);

        // Behaviour hooks: scroll lock class, Escape handling and reduced-motion respect.
        $this->assertStringContainsString('shell-locked', $html);
        $this->assertStringContainsString("e.key === 'Escape'", $html);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $html);
        $this->assertStringContainsString('@media (max-width: 859.98px)', $html);
    }

    public function test_the_menu_button_comes_first_in_the_top_bar(): void
    {
        $html = $this->signedInPage('dashboard');

        $this->assertMatchesRegularExpression('/<header class="topbar">\s*<button[^>]*data-shell-menu/', $html);
    }

    public function test_data_tables_sit_inside_a_horizontal_scroll_wrapper(): void
    {
        foreach (['invoices.index', 'invoices.show', 'operator.plans.index'] as $routeName) {
            $html = $this->signedInPage($routeName);

            $this->assertMatchesRegularExpression(
                '/<div class="[^"]*\btable-scroll\b[^"]*"[^>]*>\s*<table/',
                $html,
                "The main table on [$routeName] must be wrapped in .table-scroll so it never widens the page."
            );
        }
    }

    public function test_forms_and_split_layouts_use_the_stacking_hook(): void
    {
        foreach (['operator.plans.index', 'dashboard'] as $routeName) {
            $this->assertStringContainsString('r-stack', $this->signedInPage($routeName), "[$routeName] needs .r-stack so it collapses to one column on phones.");
        }
    }

    public function test_page_wrappers_use_the_responsive_padding_hook(): void
    {
        foreach (['dashboard', 'invoices.index', 'operator.plans.index'] as $routeName) {
            $this->assertStringContainsString('class="page-pad"', $this->signedInPage($routeName));
        }
    }

    public function test_the_notification_bell_stays_in_the_top_bar_and_its_panel_can_pin_to_the_viewport(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSeeLivewire('notification-bell');

        Livewire::actingAs($user)->test(NotificationBell::class)
            ->call('toggle')
            ->assertSeeHtml('class="bell-panel"');
    }

    private const ACCOUNT_SECTION = '//aside[@id="app-sidebar"]//div[contains(@class,"shell-account")]';

    private function accountXpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function dashboardHtmlFor(User $user): string
    {
        return $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk()->getContent();
    }

    public function test_the_sidebar_account_section_carries_team_settings_create_team_and_log_out(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));
        $account = self::ACCOUNT_SECTION;

        $this->assertSame(1, $xpath->query($account)->length, 'The account section lives inside the sidebar drawer');
        $this->assertStringContainsString($team->name, $xpath->query($account.'//summary')->item(0)?->textContent ?? '', 'The current team is named in the account section');
        $this->assertSame(1, $xpath->query($account.'//a[@href="'.route('teams.show', $team->id).'"][span[@class="team-label"][normalize-space()="Team settings"]]')->length);
        $this->assertSame(1, $xpath->query($account.'//a[@href="'.route('teams.create').'"][span[@class="team-label"][normalize-space()="Create new team"]]')->length);

        $logout = $xpath->query($account.'//form[@method="POST"][@action="'.route('logout').'"]');
        $this->assertSame(1, $logout->length, 'Log out is a POST form');
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $logout->item(0))->length);
        $this->assertSame(1, $xpath->query('.//button[@type="submit"][span[@class="team-label"][normalize-space()="Log out"]]', $logout->item(0))->length);
        $this->assertSame(0, $xpath->query('//a[@href="'.route('logout').'"]')->length, 'There is no GET logout link');
    }

    public function test_create_team_is_left_out_when_the_user_may_not_create_one(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Gate::before(fn ($authUser, $ability, $arguments) => $ability === 'create' && ($arguments[0] ?? null) instanceof Team ? false : null);

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));

        $this->assertSame(1, $xpath->query(self::ACCOUNT_SECTION.'//a[@href="'.route('teams.show', $user->currentTeam->id).'"]')->length);
        $this->assertSame(0, $xpath->query('//a[@href="'.route('teams.create').'"]')->length);
    }

    public function test_a_user_in_several_teams_gets_one_switch_form_per_team_with_the_current_one_marked(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $current = $user->currentTeam;
        $other = Team::factory()->create(['user_id' => $user->id, 'name' => 'Second Account Team', 'personal_team' => false]);

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));
        $forms = $xpath->query(self::ACCOUNT_SECTION.'//form[@method="POST"][@action="'.route('current-team.update').'"]');

        $this->assertSame(2, $forms->length, 'One switch form per team the user belongs to');
        $this->assertSame(1, $xpath->query(self::ACCOUNT_SECTION.'//form[@action="'.route('current-team.update').'"][input[@name="_method"][@value="PUT"]][input[@name="_token"]][input[@name="team_id"][@value="'.$other->id.'"]]//button[@type="submit"][contains(.,"Second Account Team")][not(@aria-current)]')->length);
        $this->assertSame(1, $xpath->query(self::ACCOUNT_SECTION.'//form[@action="'.route('current-team.update').'"][input[@name="team_id"][@value="'.$current->id.'"]]//button[@type="submit"][@aria-current="true"]')->length, 'The current team is marked');
        $this->assertSame(1, $xpath->query(self::ACCOUNT_SECTION.'//*[normalize-space()="Switch teams"]')->length);
    }

    public function test_a_user_in_one_team_gets_no_switch_forms(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));

        $this->assertSame(1, $xpath->query(self::ACCOUNT_SECTION)->length);
        $this->assertSame(0, $xpath->query('//form[@action="'.route('current-team.update').'"]')->length);
        $this->assertSame(0, $xpath->query(self::ACCOUNT_SECTION.'//*[normalize-space()="Switch teams"]')->length);
    }

    public function test_the_legacy_jetstream_bar_is_not_rendered_and_content_starts_under_the_fixed_top_bar(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->dashboardHtmlFor($user);

        $this->assertDoesNotMatchRegularExpression('/<nav\b[^>]*\bwire:id=/', $html, 'The stock Jetstream bar (a Livewire nav) must not render at any width');
        $this->assertStringNotContainsString('Manage Account', $html, 'Text only the legacy bar rendered');
        // Its 64px of flow height is gone: the content wrapper begins at the top bar height token.
        $this->assertMatchesRegularExpression('/--topbar-h:\s*54px/', $html);
        $this->assertMatchesRegularExpression('/\.topbar\s*\{[^}]*\bheight:\s*var\(--topbar-h\)/', $html);
        $this->assertMatchesRegularExpression('/\.content-wrap\s*\{[^}]*\bpadding-top:\s*var\(--topbar-h\)/', $html);
    }

    public function test_no_css_rule_hides_the_account_section_at_any_width(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->dashboardHtmlFor($user);

        $this->assertSame(1, $this->accountXpath($html)->query(self::ACCOUNT_SECTION)->length);

        preg_match_all('/<style\b[^>]*>(.*?)<\/style>/s', $html, $blocks);
        $css = implode("\n", $blocks[1]);
        $this->assertNotSame('', $css);

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $checked = 0;
        foreach ($rules as [, $selector, $declarations]) {
            // The disclosure triangle of <details> is the one thing in there that is meant to be hidden.
            if (preg_match('/shell-account|team-switch|logout-btn|team-row/', $selector) !== 1 || str_contains($selector, 'details-marker')) {
                continue;
            }
            $checked++;
            $this->assertDoesNotMatchRegularExpression('/display\s*:\s*none|visibility\s*:\s*hidden/', $declarations, 'A rule hides the account section: '.trim($selector));
        }
        $this->assertGreaterThan(0, $checked, 'The account section is styled by rules this guard can see');
    }

    public function test_the_drawer_focus_trap_skips_the_collapsed_team_list(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->assertStringContainsString('a[href], button:not([disabled]), summary', $this->dashboardHtmlFor($user));
        $this->assertStringContainsString('offsetParent !== null', $this->dashboardHtmlFor($user));
    }
}
