<?php

namespace Tests\Feature\Billing;

use App\Livewire\Billing\SeatsPanel;
use App\Models\BillingInvoice;
use App\Models\BillingSeatChange;
use App\Models\BillingSubscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\RecordPayment;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PaystackFixtures;
use Tests\TestCase;

/**
 * The customer Seats screen (/seats). Prices are made-up fixtures. Frozen clock: 5 Nov 2026 09:00, period
 * 26 Oct to 25 Nov (30 days, 20 left), so 3 seats at R10.00 come to R10.00 x 3 x 20 / 30 = R20.00.
 */
class SeatsScreenTest extends TestCase
{
    use BillingFixtures;
    use PaystackFixtures;
    use RefreshDatabase;

    private const SEAT_PRICE = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configurePaystack();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Team, 2: BillingSubscription} */
    private function scene(array $plan = [], array $sub = [], bool $withPrice = true): array
    {
        $owner = $this->makeOwner();
        $team = $owner->currentTeam;
        $this->linkToInfoDot($team);
        $plan = $this->makePlan(array_merge(
            ['name' => 'Meridian Team', 'seat_limit' => 10],
            $withPrice ? ['extra_seat_price_monthly_cents' => self::SEAT_PRICE, 'extra_seat_price_annual_cents' => 10000] : [],
            $plan,
        ));

        return [$owner, $team, $this->makeSubscription($team, $plan, $sub)];
    }

    private function invoices(Team $team)
    {
        return BillingInvoice::withoutGlobalScope('team')->where('team_id', $team->id);
    }

    private function changes(BillingSubscription $sub)
    {
        return BillingSeatChange::withoutGlobalScope('team')->where('subscription_id', $sub->id);
    }

    // ---------------------------------------------------------------- authorization

    public function test_the_owner_and_an_admin_can_open_the_seats_screen(): void
    {
        [$owner, $team] = $this->scene();
        $admin = $this->makeMember($team, 'admin');

        foreach ([$owner, $admin] as $user) {
            $this->actingAs($user)->get('/seats')->assertOk()->assertSee('Meridian Team');
        }
    }

    public function test_plain_members_are_refused(): void
    {
        [, $team] = $this->scene();

        foreach (['editor', 'viewer'] as $role) {
            $this->actingAs($this->makeMember($team, $role))->get('/seats')->assertForbidden();
        }
    }

    public function test_someone_whose_current_team_is_not_theirs_is_refused(): void
    {
        [, $team] = $this->scene();
        $stranger = $this->makeOwner();
        $stranger->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($stranger->fresh())->get('/seats')->assertForbidden();
    }

    public function test_a_platform_operator_who_is_not_on_the_team_is_refused(): void
    {
        [, $team] = $this->scene();
        $operator = $this->makeOperator();
        $operator->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($operator->fresh())->get('/seats')->assertForbidden();
    }

    public function test_a_person_with_no_current_team_is_refused_and_a_guest_is_sent_to_sign_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/seats')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/seats')->assertRedirect('/login');
    }

    public function test_the_livewire_actions_are_refused_to_a_plain_member_and_nothing_is_billed(): void
    {
        [, $team, $sub] = $this->scene();

        foreach (['editor', 'viewer'] as $role) {
            Livewire::actingAs($this->makeMember($team, $role))->test(SeatsPanel::class)->assertForbidden();
        }

        $this->assertSame(0, $this->invoices($team)->count());
        $this->assertSame(0, $this->changes($sub)->count());
    }

    public function test_the_sidebar_link_is_shown_to_managers_only(): void
    {
        [$owner, $team] = $this->scene();

        $this->actingAs($owner)->get('/dashboard')->assertSee(route('seats.show'), false);
        $this->actingAs($this->makeMember($team, 'viewer'))->get('/dashboard')->assertDontSee(route('seats.show'), false);
    }

    public function test_the_browser_cannot_choose_the_request_key_or_the_result(): void
    {
        [$owner] = $this->scene();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($owner)->test(SeatsPanel::class)->set('requestKey', 'chosen-by-the-browser');
    }

    // ---------------------------------------------------------------- what it shows

    public function test_it_shows_the_plan_cycle_included_settled_extra_seats_and_the_price(): void
    {
        [$owner] = $this->scene(sub: ['extra_seats' => 4]);
        $price = (new Money(self::SEAT_PRICE, 'ZAR'))->format();

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->assertSee('Meridian Team')
            ->assertSee('Monthly billing')
            ->assertSee('10 seats')
            ->assertSee('14 seats in total')
            ->assertSee($price)
            ->assertSee('25 Nov 2026')
            ->assertSet('targetExtraSeats', 4);
    }

    public function test_an_annual_team_sees_the_annual_price(): void
    {
        [$owner] = $this->scene(sub: ['billing_cycle' => 'annual']);

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->assertSee((new Money(10000, 'ZAR'))->format())
            ->assertSee('per year');
    }

    public function test_a_plan_with_no_extra_seat_price_says_so_and_offers_no_form(): void
    {
        [$owner, $team, $sub] = $this->scene(withPrice: false);

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->assertSee('Extra seats are not available on the Meridian Team plan')
            ->assertSee('Not available on this plan')
            ->assertDontSee('Change extra seats')
            ->assertDontSee('Confirm change');

        $this->assertSame(0, $this->invoices($team)->count());
        $this->assertSame(0, $this->changes($sub)->count());
    }

    public function test_a_zero_price_is_not_a_price(): void
    {
        [$owner] = $this->scene(['extra_seat_price_monthly_cents' => 0]);

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->assertSee('Not available on this plan')
            ->assertDontSee('Confirm change');
    }

    public function test_a_priceless_plan_that_still_holds_seats_can_only_be_reduced(): void
    {
        [$owner, $team, $sub] = $this->scene(sub: ['extra_seats' => 3], withPrice: false);

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->assertSee('You can still reduce the extra seats you already have')
            ->assertSee('Confirm change')
            ->set('targetExtraSeats', 5)->call('submit')
            ->assertHasErrors('seats')
            ->assertSee('This plan does not sell extra seats.');

        $this->assertSame(0, $this->invoices($team)->count());
        $this->assertSame(3, $sub->fresh()->extra_seats);
    }

    public function test_a_team_not_linked_to_an_infodot_company_cannot_buy_seats(): void
    {
        [$owner, $team, $sub] = $this->scene();
        $team->forceFill(['infodot_team_uuid' => null])->save();

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->assertSee('not linked to an InfoDot company')
            ->assertDontSee('Confirm change')
            ->set('targetExtraSeats', 3)->call('submit')
            ->assertHasErrors('seats');

        $this->assertSame(0, $this->invoices($team)->count());
        $this->assertSame(0, $this->changes($sub)->count());
    }

    public function test_a_team_without_a_running_subscription_is_told_and_cannot_submit(): void
    {
        $owner = $this->makeOwner();

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->assertSee('No running subscription')
            ->assertDontSee('Confirm change')
            ->call('submit')
            ->assertHasErrors('seats');
    }

    // ---------------------------------------------------------------- adding

    public function test_adding_seats_raises_the_pro_rata_invoice_and_the_seats_do_not_count_yet(): void
    {
        [$owner, $team, $sub] = $this->scene();

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->set('targetExtraSeats', 3)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('count only after it is paid')
            ->assertSee('pro rata')
            ->assertSee((new Money(2000, 'ZAR'))->format())
            ->assertSee('Seat purchase waiting for payment');

        $invoice = $this->invoices($team)->sole();
        $this->assertSame(2000, $invoice->total_cents);
        $this->assertSame('open', $invoice->status);
        $this->assertSame(0, $sub->fresh()->extra_seats, 'unpaid seats do not count');

        $change = $this->changes($sub)->sole();
        $this->assertSame('add', $change->kind);
        $this->assertSame(BillingSeatChange::AWAITING_PAYMENT, $change->status);
        $this->assertSame($invoice->id, $change->invoice_id);
        $this->assertSame($owner->id, $change->requested_by);
    }

    public function test_the_unpaid_purchase_has_a_pay_now_form_into_the_existing_invoice_payment_route(): void
    {
        [$owner, $team] = $this->scene();

        Livewire::actingAs($owner)->test(SeatsPanel::class)->set('targetExtraSeats', 2)->call('submit');
        $invoice = $this->invoices($team)->sole();

        // A fresh load of the page (as after a redirect or a return from Paystack) still offers it.
        $this->actingAs($owner)->get('/seats')
            ->assertOk()
            ->assertSee('Pay now')
            ->assertSee('action="'.route('invoices.pay', $invoice).'"', false)
            ->assertSee(route('invoices.show', $invoice), false)
            ->assertSee($invoice->invoice_number);
    }

    public function test_without_paystack_the_page_says_why_there_is_no_pay_now_button(): void
    {
        [$owner, $team] = $this->scene();
        Livewire::actingAs($owner)->test(SeatsPanel::class)->set('targetExtraSeats', 2)->call('submit');
        $invoice = $this->invoices($team)->sole();

        $this->unconfigurePaystack();

        $this->actingAs($owner)->get('/seats')
            ->assertOk()
            ->assertDontSee('action="'.route('invoices.pay', $invoice).'"', false)
            ->assertSee(route('invoices.show', $invoice), false);
    }

    public function test_pay_now_posts_to_the_existing_flow_which_goes_to_paystack_checkout(): void
    {
        [$owner, $team] = $this->scene();
        Livewire::actingAs($owner)->test(SeatsPanel::class)->set('targetExtraSeats', 2)->call('submit');
        $invoice = $this->invoices($team)->sole();

        $this->fakeHttp(['api.paystack.co/transaction/initialize' => fn ($r) => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/seatscode', 'access_code' => 'seatscode', 'reference' => $r->data()['reference']],
        ])]);

        $this->actingAs($owner)->post(route('invoices.pay', $invoice))->assertRedirect('https://checkout.paystack.com/seatscode');
    }

    public function test_paying_the_invoice_makes_the_seats_count_and_the_screen_shows_them(): void
    {
        [$owner, $team, $sub] = $this->scene();
        Livewire::actingAs($owner)->test(SeatsPanel::class)->set('targetExtraSeats', 2)->call('submit');
        $invoice = $this->invoices($team)->sole();

        app(RecordPayment::class)->apply($invoice, $invoice->balanceMoney(), method: 'manual');

        $this->assertSame(2, $sub->fresh()->extra_seats);
        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->assertDontSee('Seat purchase waiting for payment')
            ->assertSee('12 seats in total');
    }

    public function test_a_second_purchase_while_one_is_unpaid_is_refused_in_plain_words(): void
    {
        [$owner, $team] = $this->scene();

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->set('targetExtraSeats', 2)->call('submit')->assertHasNoErrors()
            ->set('targetExtraSeats', 5)->call('submit')
            ->assertHasErrors('seats')
            ->assertSee('An earlier seat purchase is still waiting for payment.');

        $this->assertSame(1, $this->invoices($team)->count());
    }

    // ---------------------------------------------------------------- reducing

    public function test_reducing_schedules_it_for_period_end_with_no_invoice_and_no_refund(): void
    {
        [$owner, $team, $sub] = $this->scene(sub: ['extra_seats' => 5]);

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->set('targetExtraSeats', 2)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('drop from 5 to 2 on 25 Nov 2026')
            ->assertSee('nothing is refunded');

        $sub->refresh();
        $this->assertSame(5, $sub->extra_seats, 'seats stay until the period ends');
        $this->assertSame(2, $sub->pending_extra_seats);
        $this->assertSame(0, $this->invoices($team)->count());
        $this->assertSame('reduce', $this->changes($sub)->sole()->kind);

        // The scheduled reduction is on the screen for a fresh visit, too.
        $this->actingAs($owner)->get('/seats')->assertSee('Your extra seats drop from 5 to 2 on 25 Nov 2026');
    }

    public function test_asking_for_the_current_count_cancels_a_scheduled_reduction(): void
    {
        [$owner, , $sub] = $this->scene(sub: ['extra_seats' => 5, 'pending_extra_seats' => 2]);

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->assertSee('drop from 5 to 2')
            ->set('targetExtraSeats', 5)
            ->call('submit')
            ->assertSee('nothing changed')
            ->assertDontSee('Your extra seats drop from');

        $this->assertNull($sub->fresh()->pending_extra_seats);
    }

    // ---------------------------------------------------------------- idempotency and refusals

    public function test_a_double_submit_of_the_same_form_makes_one_invoice(): void
    {
        [$owner, $team, $sub] = $this->scene();

        $page = Livewire::actingAs($owner)->test(SeatsPanel::class)->set('targetExtraSeats', 3);
        $key = $page->get('requestKey');
        $page->call('submit')->call('submit');

        $page->assertHasNoErrors()->assertSee('already submitted');
        $this->assertSame($key, $page->get('requestKey'), 'the key is kept for the same number');
        $this->assertSame(1, $this->invoices($team)->count());
        $this->assertSame(1, $this->changes($sub)->count());
        $this->assertSame($key, $this->changes($sub)->sole()->request_key);
    }

    public function test_a_different_number_is_a_new_request_with_a_new_key(): void
    {
        [$owner, , $sub] = $this->scene(sub: ['extra_seats' => 4]);

        $page = Livewire::actingAs($owner)->test(SeatsPanel::class);
        $first = $page->get('requestKey');
        $page->set('targetExtraSeats', 2)->call('submit');
        $page->set('targetExtraSeats', 1);

        $this->assertNotSame($first, $page->get('requestKey'));
        $page->call('submit')->assertHasNoErrors();
        $this->assertSame(2, $this->changes($sub)->count());
        $this->assertSame(1, $sub->fresh()->pending_extra_seats, 'the latest request before renewal wins');
    }

    public function test_the_same_key_from_two_page_loads_is_never_shared(): void
    {
        [$owner] = $this->scene();

        $a = Livewire::actingAs($owner)->test(SeatsPanel::class)->get('requestKey');
        $b = Livewire::actingAs($owner)->test(SeatsPanel::class)->get('requestKey');

        $this->assertNotSame($a, $b);
    }

    public function test_bad_numbers_are_refused_before_anything_is_billed(): void
    {
        [$owner, $team, $sub] = $this->scene();
        $max = (int) config('billing.seats.max_extra', 500);

        $page = Livewire::actingAs($owner)->test(SeatsPanel::class);

        $page->set('targetExtraSeats', -1)->call('submit')->assertHasErrors('targetExtraSeats')->assertSee('cannot be negative');
        $page->set('targetExtraSeats', 'abc')->call('submit')->assertHasErrors('targetExtraSeats')->assertSee('whole number');
        $page->set('targetExtraSeats', '')->call('submit')->assertHasErrors('targetExtraSeats')->assertSee('Enter how many extra seats');
        $page->set('targetExtraSeats', 1.5)->call('submit')->assertHasErrors('targetExtraSeats');
        $page->set('targetExtraSeats', $max + 1)->call('submit')->assertHasErrors('targetExtraSeats')->assertSee("at most {$max} extra seats");

        $this->assertSame(0, $this->invoices($team)->count());
        $this->assertSame(0, $this->changes($sub)->count());
    }

    public function test_a_trial_cannot_buy_seats_and_the_refusal_says_so(): void
    {
        [$owner, $team, $sub] = $this->scene(sub: ['status' => 'trialing', 'trial_ends_at' => now()->addDays(5)]);

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->set('targetExtraSeats', 2)->call('submit')
            ->assertHasErrors('seats')
            ->assertSee('Extra seats can be bought once the trial has ended.');

        $this->assertSame(0, $this->invoices($team)->count());
        $this->assertSame(0, $this->changes($sub)->count());
    }

    public function test_the_last_day_of_the_period_is_refused_with_its_reason(): void
    {
        [$owner, $team] = $this->scene(sub: ['current_period_start' => now()->subDays(30), 'current_period_end' => now()->startOfDay()]);

        Livewire::actingAs($owner)->test(SeatsPanel::class)
            ->set('targetExtraSeats', 2)->call('submit')
            ->assertSee('Buy the seats after it renews.');

        $this->assertSame(0, $this->invoices($team)->count());
    }

    public function test_it_only_ever_touches_the_current_teams_subscription(): void
    {
        [$owner, , $sub] = $this->scene();
        $otherOwner = $this->makeOwner();
        $otherPlan = $this->makePlan(['extra_seat_price_monthly_cents' => 500]);
        $other = $this->makeSubscription($otherOwner->currentTeam, $otherPlan);

        Livewire::actingAs($owner)->test(SeatsPanel::class)->set('targetExtraSeats', 3)->call('submit');

        $this->assertSame(1, $this->changes($sub)->count());
        $this->assertSame(0, $this->changes($other)->count());
        $this->assertSame(0, $this->invoices($otherOwner->currentTeam)->count());
    }
}
