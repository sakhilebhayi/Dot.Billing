<?php

namespace App\Support;

use App\Exceptions\Billing\BillingRuleViolation;
use InvalidArgumentException;
use JsonSerializable;

/**
 * An amount of money as integer minor units (cents) in one currency.
 *
 * All billing arithmetic goes through here: integers only, so there is no float
 * rounding, and two currencies can never be added together by accident.
 *
 * Every supported currency is assumed to have two minor-unit digits (ZAR, USD, EUR,
 * GBP...). Zero- and three-decimal currencies (JPY, KWD) are not modelled.
 */
final readonly class Money implements JsonSerializable
{
    public string $currency;

    public function __construct(public int $cents, string $currency)
    {
        $currency = strtoupper($currency);

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Currency must be a three-letter code, got '{$currency}'.");
        }

        $this->currency = $currency;
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Convert a legacy decimal (a "500.00" string out of a decimal(10,2) column, or an int or float
     * written by old code) to cents. Strings are converted by digit manipulation so no float is ever
     * involved; a third decimal digit rounds half up.
     */
    public static function decimalToCents(string|int|float|null $decimal): int
    {
        if ($decimal === null || $decimal === '') {
            return 0;
        }

        if (is_int($decimal)) {
            return $decimal * 100;
        }

        if (is_float($decimal)) {
            return (int) round($decimal * 100);
        }

        $decimal = trim($decimal);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $m)) {
            throw new InvalidArgumentException("Not a decimal amount: '{$decimal}'.");
        }

        $fraction = str_pad($m[3] ?? '', 3, '0');
        $cents = ((int) $m[2]) * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $m[1] === '-' ? -$cents : $cents;
    }

    public static function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    /**
     * Parse an amount a person typed. Strict on purpose: digits with at most two decimals, greater
     * than zero (or zero too when $allowZero, for a free plan's price). No thousands separators
     * (a comma is a decimal mark in half the world), no signs.
     */
    public static function parse(string $input, string $currency, bool $allowZero = false): self
    {
        $input = trim($input);

        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $input)) {
            throw BillingRuleViolation::because('invalid_amount', 'Enter an amount like 1250.00 (digits, at most two decimals, no separators).');
        }

        $money = new self(self::decimalToCents($input), $currency);

        if ($money->cents <= 0 && ! $allowZero) {
            throw BillingRuleViolation::because('invalid_amount', 'The amount must be greater than zero.');
        }

        return $money;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    /**
     * A share of this amount in basis points (1500 = 15%), rounded half up, integers only.
     */
    public function percentage(int $basisPoints): self
    {
        $scaled = $this->cents * $basisPoints;
        $rounded = $scaled >= 0 ? intdiv($scaled + 5000, 10000) : -intdiv(-$scaled + 5000, 10000);

        return new self($rounded, $this->currency);
    }

    /** This amount times a whole number (a unit price times a quantity). */
    public function times(int $factor): self
    {
        return new self($this->cents * $factor, $this->currency);
    }

    /**
     * $part out of $whole of this amount (days left of days in the period), rounded half up to the cent,
     * integers only. $whole must be positive and $part between 0 and $whole is the intended use.
     */
    public function proRata(int $part, int $whole): self
    {
        if ($whole <= 0 || $part < 0 || $this->cents < 0) {
            throw new InvalidArgumentException('Pro rata needs a positive whole and non-negative part and amount.');
        }

        return new self(intdiv(2 * $this->cents * $part + $whole, 2 * $whole), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents > $other->cents;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->cents === $other->cents;
    }

    /** "1,234.50" */
    public function formatAmount(): string
    {
        $abs = abs($this->cents);

        return ($this->cents < 0 ? '-' : '').number_format(intdiv($abs, 100)).'.'.sprintf('%02d', $abs % 100);
    }

    /** "ZAR 1,234.50" */
    public function format(): string
    {
        return $this->currency.' '.$this->formatAmount();
    }

    /** The two-decimal string to mirror into legacy decimal columns. */
    public function toDecimal(): string
    {
        return self::centsToDecimal($this->cents);
    }

    /**
     * Group rows of ['currency' => 'ZAR', 'cents' => 1200] into one Money per currency, sorted by
     * currency code. This is the only sanctioned way to "total" a mixed set: never across currencies.
     *
     * @param  iterable<array{currency: string, cents: int|string}>  $rows
     * @return array<string, Money>
     */
    public static function totalsByCurrency(iterable $rows): array
    {
        $sums = [];

        foreach ($rows as $row) {
            $currency = strtoupper((string) $row['currency']);
            $sums[$currency] = ($sums[$currency] ?? 0) + (int) $row['cents'];
        }

        ksort($sums);

        $totals = [];

        foreach ($sums as $currency => $cents) {
            $totals[$currency] = new self($cents, $currency);
        }

        return $totals;
    }

    public function jsonSerialize(): array
    {
        return ['cents' => $this->cents, 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw BillingRuleViolation::because('currency_mismatch', "Cannot combine {$this->currency} and {$other->currency}.");
        }
    }
}
