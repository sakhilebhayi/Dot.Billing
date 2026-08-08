<?php

namespace Tests\Feature;

use App\Livewire\Billing\PaymentReliability;
use App\Models\BillingInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentReliabilityComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_the_cushion_content_when_data_is_available(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        BillingInvoice::create([
            'team_id' => $team->id,
            'invoice_number' => 'INV-1',
            'status' => 'paid',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
            'currency' => 'USD',
            'due_date' => now()->subDays(10),
            'paid_at' => now()->subDays(12),
        ]);

        Livewire::test(PaymentReliability::class)
            ->assertSee('Payment Reliability')
            ->assertSee('100%');
    }

    public function test_renders_without_the_cushion_content_when_no_data(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        Livewire::test(PaymentReliability::class)
            ->assertDontSee('%');
    }
}
