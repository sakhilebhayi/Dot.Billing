<?php

namespace App\Support;

use JsonSerializable;
use Stringable;

/**
 * Wraps a value that must never reach a log, a stack trace or a response (a bank account number). PHP puts
 * function arguments into exception traces, and a plain string would be printed there; an object prints as
 * "Object(App\Support\Redacted)". The value comes out only through reveal(), at the one place that sends it.
 */
final class Redacted implements JsonSerializable, Stringable
{
    /** The value lives inside a closure, which print_r, var_dump and var_export never open. */
    private readonly \Closure $value;

    public function __construct(string $value)
    {
        $this->value = static fn (): string => $value;
    }

    public function reveal(): string
    {
        return ($this->value)();
    }

    public function last4(): string
    {
        return substr($this->reveal(), -4);
    }

    public function __toString(): string
    {
        return '[redacted]';
    }

    public function jsonSerialize(): string
    {
        return '[redacted]';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    public function __serialize(): array
    {
        return ['value' => '[redacted]'];
    }
}
