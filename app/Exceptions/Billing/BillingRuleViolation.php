<?php

namespace App\Exceptions\Billing;

use DomainException;

/**
 * A billing rule said no. Thrown by the billing services; callers (Livewire
 * components, commands) turn it into a message for the person who asked.
 *
 * `reason` is a stable machine-readable code (plan_archived, already_subscribed,
 * overpayment, ...) so tests and callers never match on prose.
 */
class BillingRuleViolation extends DomainException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : str_replace('_', ' ', $reason));
    }

    public static function because(string $reason, string $message = ''): self
    {
        return new self($reason, $message);
    }
}
