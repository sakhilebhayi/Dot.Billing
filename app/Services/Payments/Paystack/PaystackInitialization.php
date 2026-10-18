<?php

namespace App\Services\Payments\Paystack;

/**
 * What Paystack returned for a new transaction. The access code is part of the checkout address and is
 * kept only inside it; it is never stored on its own or logged.
 */
final readonly class PaystackInitialization
{
    public function __construct(public string $authorizationUrl, public string $reference) {}
}
