<?php

namespace Tests\Feature\Billing;

use App\Models\BillingInvoice;
use App\Models\Team;
use App\Services\Billing\InvoiceNumberAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BillingFixtures;
use Tests\TestCase;

class InvoiceNumberTest extends TestCase
{
    use BillingFixtures;
    use RefreshDatabase;

    private InvoiceNumberAllocator $numbers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->numbers = app(InvoiceNumberAllocator::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_numbers_are_sequential_per_team_in_the_default_format(): void
    {
        $team = Team::factory()->create();

        $this->assertSame('INV-2026-0001', $this->numbers->next($team->id));
        $this->assertSame('INV-2026-0002', $this->numbers->next($team->id));
        $this->assertSame('INV-2026-0003', $this->numbers->next($team->id));
    }

    public function test_each_team_has_its_own_sequence(): void
    {
        $a = Team::factory()->create();
        $b = Team::factory()->create();

        $this->numbers->next($a->id);
        $this->numbers->next($a->id);

        $this->assertSame('INV-2026-0001', $this->numbers->next($b->id));
        $this->assertSame('INV-2026-0003', $this->numbers->next($a->id));
    }

    public function test_the_sequence_restarts_each_year(): void
    {
        $team = Team::factory()->create();
        $this->numbers->next($team->id);

        Carbon::setTestNow('2027-01-02 08:00:00');

        $this->assertSame('INV-2027-0001', $this->numbers->next($team->id));
    }

    public function test_the_format_and_padding_are_configurable(): void
    {
        $team = Team::factory()->create();
        config(['billing.invoice.number_format' => 'DOT/{YY}/{MM}/{seq}', 'billing.invoice.number_pad' => 6]);

        $this->assertSame('DOT/26/03/000001', $this->numbers->next($team->id));
    }

    public function test_a_format_without_a_sequence_token_still_produces_unique_numbers(): void
    {
        $team = Team::factory()->create();
        config(['billing.invoice.number_format' => 'BILL-{YYYY}']);

        $this->assertNotSame($this->numbers->next($team->id), $this->numbers->next($team->id));
    }

    public function test_a_number_already_used_by_an_older_invoice_is_skipped(): void
    {
        $team = Team::factory()->create();
        $this->makeInvoice($team, ['invoice_number' => 'INV-2026-0001']);
        $this->makeInvoice($team, ['invoice_number' => 'INV-2026-0002']);

        $this->assertSame('INV-2026-0003', $this->numbers->next($team->id));
    }

    public function test_a_rolled_back_allocation_does_not_burn_a_number(): void
    {
        $team = Team::factory()->create();

        try {
            DB::transaction(function () use ($team) {
                $this->numbers->next($team->id);
                throw new \RuntimeException('invoice creation failed after numbering');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('INV-2026-0001', $this->numbers->next($team->id));
    }

    public function test_the_same_number_cannot_exist_twice_in_one_team_but_can_in_another(): void
    {
        $a = Team::factory()->create();
        $b = Team::factory()->create();
        $this->makeInvoice($a, ['invoice_number' => 'INV-2026-0001']);
        $this->makeInvoice($b, ['invoice_number' => 'INV-2026-0001']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeInvoice($a, ['invoice_number' => 'INV-2026-0001']);
    }

    public function test_it_reads_back_through_the_model_as_a_string(): void
    {
        $team = Team::factory()->create();
        $invoice = BillingInvoice::create(['team_id' => $team->id, 'invoice_number' => $this->numbers->next($team->id), 'status' => 'draft', 'currency' => 'ZAR']);

        $this->assertSame('INV-2026-0001', $invoice->fresh()->invoice_number);
    }
}
