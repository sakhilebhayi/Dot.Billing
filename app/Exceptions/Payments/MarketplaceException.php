<?php

namespace App\Exceptions\Payments;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A marketplace request that cannot be honoured. Renders itself as {"error": code, "message": ...} with an
 * HTTP status. The message is always safe to show the calling platform: no key, no response body, no account
 * number.
 */
class MarketplaceException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    public static function because(string $code, string $message, int $httpStatus): self
    {
        return new self($code, $message, $httpStatus);
    }

    /** Paystack could not be used (down, unconfigured, or answered with something unreadable). */
    public static function providerUnavailable(): self
    {
        return new self('payment_provider_unavailable', 'The payment provider is not available right now. Nothing was changed; please try again shortly.', 503);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->errorCode, 'message' => $this->getMessage()], $this->httpStatus);
    }
}
