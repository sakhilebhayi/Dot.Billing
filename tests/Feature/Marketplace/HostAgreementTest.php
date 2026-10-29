<?php

namespace Tests\Feature\Marketplace;

use App\Models\BillingAuditEvent;
use App\Models\HostAgreement;
use App\Models\PayoutAccount;
use App\Models\TicketPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

/**
 * The host term (docs/host-agreement-draft.md): accepted per payout account and version, required before a ticket
 * can be created, and only the CURRENT version counts.
 */
class HostAgreementTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    private PayoutAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->configureMarketplace();
        $this->fakeMarketplaceHttp();
        $this->account = $this->makePayoutAccount(['host_ref' => 'host-1'], agreed: false);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function accept(array $body = [], ?int $id = null)
    {
        return $this->signed('POST', '/api/payout-accounts/'.($id ?? $this->account->id).'/agreement', array_merge(['version' => 'v1', 'accepted_by_ref' => 'user-77'], $body));
    }

    private function ticketBody(array $overrides = []): array
    {
        return array_merge([
            'ticket_ref' => 'tkt-0001', 'payout_account_id' => $this->account->id, 'buyer_email' => 'a@example.test',
            'amount_cents' => 20000, 'currency' => 'ZAR', 'description' => 'Masterclass',
        ], $overrides);
    }

    public function test_accepting_the_current_version_is_recorded_with_who_and_when(): void
    {
        $this->accept()->assertCreated()->assertExactJson([
            'payout_account_id' => $this->account->id, 'version' => 'v1', 'accepted_at' => '2026-11-05T09:00:00+00:00',
            'accepted_by_ref' => 'user-77', 'current_version' => 'v1', 'current' => true,
        ]);

        $row = HostAgreement::sole();
        $this->assertSame([$this->account->id, 'v1', 'user-77'], [$row->payout_account_id, $row->version, $row->accepted_by_ref]);
        $this->assertSame(1, BillingAuditEvent::withoutGlobalScope('team')->where('action', 'host_agreement.accepted')->count());
    }

    public function test_accepting_again_is_idempotent_and_the_first_acceptance_stands(): void
    {
        $this->accept()->assertCreated();
        Carbon::setTestNow('2026-11-06 10:00:00');

        $this->accept(['accepted_by_ref' => 'someone-else'])->assertOk()->assertJson([
            'accepted_at' => '2026-11-05T09:00:00+00:00', 'accepted_by_ref' => 'user-77', 'current' => true,
        ]);

        $this->assertSame(1, HostAgreement::count());
        $this->assertSame(1, BillingAuditEvent::withoutGlobalScope('team')->where('action', 'host_agreement.accepted')->count());
    }

    public function test_each_version_is_its_own_acceptance_and_only_the_current_one_counts(): void
    {
        $this->accept(['version' => 'v0'])->assertCreated()->assertJson(['current' => false, 'current_version' => 'v1']);

        $this->assertSame(1, HostAgreement::count());
        $this->signed('POST', '/api/seminar-tickets', $this->ticketBody())->assertStatus(422)->assertJson(['error' => 'agreement_required']);

        $this->accept()->assertCreated();
        $this->assertSame(2, HostAgreement::count());
        $this->signed('POST', '/api/seminar-tickets', $this->ticketBody())->assertCreated();
    }

    public function test_a_ticket_cannot_be_created_without_an_accepted_agreement_and_nothing_is_left_behind(): void
    {
        $this->signed('POST', '/api/seminar-tickets', $this->ticketBody())
            ->assertStatus(422)->assertExactJson(['error' => 'agreement_required', 'message' => 'The host has not accepted the current host agreement.']);

        $this->assertSame(0, TicketPayment::count());
        Http::assertNothingSent();
    }

    public function test_one_hosts_acceptance_does_not_cover_another_host(): void
    {
        $other = $this->makePayoutAccount(['host_ref' => 'host-2'], agreed: true);

        $this->signed('POST', '/api/seminar-tickets', $this->ticketBody(['payout_account_id' => $this->account->id]))->assertStatus(422)->assertJson(['error' => 'agreement_required']);
        $this->signed('POST', '/api/seminar-tickets', $this->ticketBody(['payout_account_id' => $other->id]))->assertCreated();
    }

    public function test_changing_the_version_asks_every_host_to_accept_again(): void
    {
        $this->accept()->assertCreated();
        $this->signed('POST', '/api/seminar-tickets', $this->ticketBody())->assertCreated();

        config(['billing.marketplace.agreement_version' => 'v2']);

        $this->signed('POST', '/api/seminar-tickets', $this->ticketBody(['ticket_ref' => 'tkt-0002']))->assertStatus(422)->assertJson(['error' => 'agreement_required']);
        // A ticket that already exists is still answered (idempotent repeat), it is not created again.
        $this->signed('POST', '/api/seminar-tickets', $this->ticketBody())->assertOk();

        $this->accept(['version' => 'v2'])->assertCreated()->assertJson(['current' => true, 'current_version' => 'v2']);
        $this->signed('POST', '/api/seminar-tickets', $this->ticketBody(['ticket_ref' => 'tkt-0002']))->assertCreated();
    }

    public function test_the_default_version_is_v1(): void
    {
        $this->assertSame('v1', (require base_path('config/billing.php'))['marketplace']['agreement_version']);
        $this->assertSame(50, (int) (require base_path('config/billing.php'))['marketplace']['recovery_cap_percent']);
    }

    public function test_validation_and_unknown_accounts(): void
    {
        $this->accept(['version' => ''])->assertStatus(422)->assertJsonValidationErrors('version');
        $this->accept(['version' => 'v 1!'])->assertStatus(422)->assertJsonValidationErrors('version');
        $this->accept(['version' => str_repeat('a', 33)])->assertStatus(422)->assertJsonValidationErrors('version');
        $this->accept(['accepted_by_ref' => ''])->assertStatus(422)->assertJsonValidationErrors('accepted_by_ref');
        $this->accept(['accepted_by_ref' => 'has spaces'])->assertStatus(422)->assertJsonValidationErrors('accepted_by_ref');
        $this->accept([], 999999)->assertNotFound()->assertJson(['error' => 'not_found']);

        $this->assertSame(0, HostAgreement::count());
    }

    public function test_it_needs_a_signed_call_from_infodot(): void
    {
        $uri = '/api/payout-accounts/'.$this->account->id.'/agreement';

        $this->json('POST', $uri, ['version' => 'v1', 'accepted_by_ref' => 'u'])->assertUnauthorized();
        $this->signed('POST', $uri, ['version' => 'v1', 'accepted_by_ref' => 'u'], platform: 'files', secret: 'files-entitlement-secret-for-tests-0003')->assertUnauthorized();
        $this->assertSame(0, HostAgreement::count());
    }

    public function test_a_marketplace_with_an_unusable_version_or_cap_refuses_to_take_money(): void
    {
        foreach ([['agreement_version' => ''], ['agreement_version' => 'v 1'], ['recovery_cap_percent' => 101], ['recovery_cap_percent' => -1], ['recovery_cap_percent' => 'half']] as $bad) {
            $this->configureMarketplace($bad);
            $this->signed('POST', '/api/seminar-tickets', $this->ticketBody())->assertStatus(503)->assertJson(['error' => 'marketplace_misconfigured']);
        }

        $this->assertSame(0, TicketPayment::count());
    }

    public function test_the_agreement_endpoint_is_a_404_while_the_marketplace_is_off(): void
    {
        config(['billing.marketplace.enabled' => false]);

        $this->accept()->assertNotFound();
        $this->assertSame(0, HostAgreement::count());
    }
}
