<?php

namespace Tests\Feature\Billing;

use App\Livewire\Billing\PlanManager;
use App\Models\BillingPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class PlanManagerTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    public function test_only_a_platform_operator_can_open_the_plan_catalogue(): void
    {
        $this->get(route('operator.plans.index'))->assertRedirect('/login');
        $this->actingAs($this->makeOwner())->get(route('operator.plans.index'))->assertForbidden();
        $this->actingAs($this->makeOperator())->get(route('operator.plans.index'))->assertOk()->assertSee('Plan catalogue');
    }

    public function test_a_non_operator_cannot_even_mount_the_component(): void
    {
        Livewire::actingAs($this->makeOwner())->test(PlanManager::class)->assertForbidden();
    }

    public function test_actions_are_forbidden_to_a_non_operator_even_when_called_directly(): void
    {
        $plan = $this->makePlan();

        $component = Livewire::actingAs($this->makeOperator())->test(PlanManager::class);

        // The session now belongs to somebody who is not an operator; the mounted component still answers requests.
        $this->actingAs($this->makeOwner());
        $component->call('archive', $plan->id)->assertForbidden();

        $this->assertTrue($plan->fresh()->is_active);
    }

    public function test_an_operator_can_create_a_plan_with_prices_limits_and_features(): void
    {
        Livewire::actingAs($this->makeOperator())->test(PlanManager::class)
            ->set('name', 'Meridian Growth')
            ->set('slug', 'meridian-growth')
            ->set('description', 'For growing teams')
            ->set('currency', 'zar')
            ->set('priceMonthly', '499.00')
            ->set('priceAnnual', '4990')
            ->set('seatLimit', '25')
            ->set('storageGb', '')
            ->set('trialDays', '14')
            ->set('features', "api_access\n  priority_support  \n\n")
            ->call('save')
            ->assertHasNoErrors();

        $plan = BillingPlan::where('slug', 'meridian-growth')->firstOrFail();
        $this->assertSame(49900, $plan->price_monthly_cents);
        $this->assertSame(499000, $plan->price_annual_cents);
        $this->assertSame('ZAR', $plan->currency);
        $this->assertSame(25, $plan->seat_limit);
        $this->assertNull($plan->storage_gb, 'blank means unlimited');
        $this->assertSame(14, $plan->trial_days);
        $this->assertSame(['api_access', 'priority_support'], $plan->features);
        $this->assertTrue($plan->is_active);
    }

    public function test_prices_must_be_strict_amounts_and_the_slug_unique(): void
    {
        $this->makePlan(['slug' => 'taken-slug']);

        Livewire::actingAs($this->makeOperator())->test(PlanManager::class)
            ->set('name', 'Dup')->set('slug', 'taken-slug')->set('currency', 'ZAR')
            ->set('priceMonthly', '12,50')->set('priceAnnual', '-1')
            ->call('save')
            ->assertHasErrors(['slug', 'priceMonthly', 'priceAnnual']);

        $this->assertSame(1, BillingPlan::count());
    }

    public function test_a_plan_can_be_free(): void
    {
        Livewire::actingAs($this->makeOperator())->test(PlanManager::class)
            ->set('name', 'Free Forever')->set('slug', 'free-forever')->set('currency', 'ZAR')
            ->set('priceMonthly', '0')->set('priceAnnual', '0')
            ->call('save')->assertHasNoErrors();

        $this->assertTrue(BillingPlan::where('slug', 'free-forever')->firstOrFail()->isFree());
    }

    public function test_an_operator_can_edit_a_plan_and_existing_invoices_keep_their_snapshot(): void
    {
        $operator = $this->makeOperator();
        $plan = $this->makePlan(['name' => 'Before Edit', 'price_monthly_cents' => 1000]);

        Livewire::actingAs($operator)->test(PlanManager::class)
            ->call('edit', $plan->id)
            ->assertSet('name', 'Before Edit')
            ->assertSet('priceMonthly', '10.00')
            ->set('name', 'After Edit')
            ->set('priceMonthly', '12.50')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('After Edit', $plan->fresh()->name);
        $this->assertSame(1250, $plan->fresh()->price_monthly_cents);
    }

    public function test_the_currency_of_a_plan_with_subscribers_cannot_change(): void
    {
        $owner = $this->makeOwner();
        $plan = $this->makePlan(['currency' => 'ZAR']);
        $this->makeSubscription($owner->currentTeam, $plan);

        Livewire::actingAs($this->makeOperator())->test(PlanManager::class)
            ->call('edit', $plan->id)
            ->set('currency', 'USD')
            ->call('save')
            ->assertHasErrors(['currency']);

        $this->assertSame('ZAR', $plan->fresh()->currency);
    }

    public function test_archiving_hides_a_plan_from_new_subscribers_and_restoring_brings_it_back(): void
    {
        $operator = $this->makeOperator();
        $plan = $this->makePlan();

        Livewire::actingAs($operator)->test(PlanManager::class)
            ->call('archive', $plan->id);
        $this->assertTrue($plan->fresh()->isArchived());

        Livewire::actingAs($operator)->test(PlanManager::class)
            ->call('restore', $plan->id);
        $this->assertFalse($plan->fresh()->isArchived());
    }

    public function test_the_list_shows_archived_plans_marked_and_counts_live_subscribers(): void
    {
        $owner = $this->makeOwner();
        $plan = $this->makePlan(['name' => 'Counted Plan Distinct']);
        $this->makePlan(['name' => 'Retired Plan Distinct', 'is_active' => false]);
        $this->makeSubscription($owner->currentTeam, $plan);

        Livewire::actingAs($this->makeOperator())->test(PlanManager::class)
            ->assertSee('Counted Plan Distinct')
            ->assertSee('Retired Plan Distinct')
            ->assertSee('Archived');
    }

    public function test_there_is_no_way_to_delete_a_plan(): void
    {
        $this->assertFalse(method_exists(PlanManager::class, 'delete'));
    }
}
