<?php

namespace App\Services\Payments\Paystack;

use App\Models\PaymentEvent;

/**
 * What PaystackSettlement did with a confirmed (or webhook-reported) transaction.
 */
final readonly class SettlementOutcome
{
    public const APPLIED = 'applied';

    public const REPLAYED = 'replayed';

    public const NOT_SUCCESSFUL = 'not_successful';

    public const HELD = 'held';

    public function __construct(
        public string $kind,
        public ?PaymentEvent $event = null,
        public ?string $transactionStatus = null,
    ) {}

    /** The money is on the invoice (now or already). */
    public function isSettled(): bool
    {
        return in_array($this->kind, [self::APPLIED, self::REPLAYED], true);
    }

    /** Money moved but an operator has to look: nothing was applied. */
    public function isHeld(): bool
    {
        return $this->kind === self::HELD;
    }
}
