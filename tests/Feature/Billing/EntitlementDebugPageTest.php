<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class EntitlementDebugPageTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    public function test_only_operators_can_open_it(): void
    {
        $this->get(route('operator.entitlements.index'))->assertRedirect('/login');
        $this->actingAs($this->makeOwner())->get(route('operator.entitlements.index'))->assertForbidden();
        $this->actingAs($this->makeOperator())->get(route('operator.entitlements.index'))->assertOk()->assertSee('Entitlement lookup');
    }

    public function test_it_shows_exactly_what_the_api_would_return_for_a_company(): void
    {
        $owner = $this->makeOwner();
        $uuid = $this->linkToInfoDot($owner->currentTeam);
        $this->makeSubscription($owner->currentTeam, $this->makePlan(['slug' => 'debug-visible-plan-slug', 'seat_limit' => 12]));

        $this->actingAs($this->makeOperator())->get(route('operator.entitlements.index', ['company' => $uuid]))
            ->assertOk()
            ->assertSee('debug-visible-plan-slug')
            ->assertSee($uuid);
    }

    public function test_an_unknown_company_says_so_plainly(): void
    {
        $this->actingAs($this->makeOperator())->get(route('operator.entitlements.index', ['company' => '99999999-9999-4999-8999-999999999999']))
            ->assertOk()
            ->assertSee('No team here is linked to that company id.');
    }
}
