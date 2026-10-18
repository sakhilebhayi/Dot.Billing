<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * A payment provider could not be used. The message is always safe to show a person: it never carries
 * a key, a response body or a status code. `reason` is the stable machine-readable code.
 *
 *  not_configured    no key, or a base URL that is not https
 *  live_key_refused  a live key without PAYSTACK_ALLOW_LIVE=true
 *  unavailable       network failure, timeout or a 5xx
 *  rejected          the provider answered with a refusal (bad key, duplicate reference, unknown transaction)
 *  invalid_response  the answer could not be read or was missing what we need
 */
class PaymentGatewayException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
