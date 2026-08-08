<?php

namespace App\Livewire\Billing;

use App\Services\PaymentReliabilityCalculator;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PaymentReliability extends Component
{
    #[Computed]
    public function cushion(): array
    {
        return (new PaymentReliabilityCalculator)->calculate();
    }

    public function render()
    {
        return view('livewire.billing.payment-reliability');
    }
}
