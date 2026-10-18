<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
