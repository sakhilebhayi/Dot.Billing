<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class BillingRolesTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    public function test_a_read_only_viewer_role_is_defined_alongside_admin_and_editor(): void
    {
        $roles = collect(Jetstream::$roles)->keyBy('key');

        $this->assertSame(['read'], $roles['viewer']->permissions);
        $this->assertContains('update', $roles['editor']->permissions);
        $this->assertContains('delete', $roles['admin']->permissions);
    }

    public function test_only_the_owner_and_admins_can_manage_billing(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $admin = $this->makeMember($team, 'admin');
        $editor = $this->makeMember($team, 'editor');
        $viewer = $this->makeMember($team, 'viewer');
        $stranger = $this->makeOwner();
        $operator = $this->makeOperator();

        $this->assertTrue(Gate::forUser($owner)->allows('manage-billing', $team));
        $this->assertTrue(Gate::forUser($admin)->allows('manage-billing', $team));
        $this->assertFalse(Gate::forUser($editor)->allows('manage-billing', $team));
        $this->assertFalse(Gate::forUser($viewer)->allows('manage-billing', $team));
        $this->assertFalse(Gate::forUser($stranger)->allows('manage-billing', $team));
        $this->assertFalse(Gate::forUser($operator)->allows('manage-billing', $team), 'a platform operator is not a team manager');
    }

    public function test_every_member_but_nobody_else_can_read_billing(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $viewer = $this->makeMember($team, 'viewer');
        $stranger = $this->makeOwner();

        $this->assertTrue(Gate::forUser($owner)->allows('view-billing', $team));
        $this->assertTrue(Gate::forUser($viewer)->allows('view-billing', $team));
        $this->assertFalse(Gate::forUser($stranger)->allows('view-billing', $team));
    }

    public function test_a_viewer_can_read_an_invoice_page_but_a_stranger_cannot(): void
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $viewer = $this->makeMember($team, 'viewer');
        $invoice = $this->makeInvoice($team);

        $this->actingAs($viewer)->get(route('invoices.show', $invoice))->assertOk();
        $this->actingAs($this->makeOwner())->get(route('invoices.show', $invoice))->assertNotFound();
    }
}
