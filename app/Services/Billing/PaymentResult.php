<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\PaymentEvent;

final readonly class PaymentResult
{
    public function __construct(
        public BillingPayment $payment,
        public PaymentEvent $event,
        public bool $replayed,
    ) {}
}
