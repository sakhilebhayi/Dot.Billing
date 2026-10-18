<?php

namespace Tests\Unit;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_holds_integer_minor_units_and_an_uppercase_currency(): void
    {
        $money = new Money(123456, 'zar');

        $this->assertSame(123456, $money->cents);
        $this->assertSame('ZAR', $money->currency);
    }

    public function test_it_rejects_a_malformed_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Money(100, 'RAND');
    }

    #[DataProvider('decimalProvider')]
    public function test_decimal_strings_convert_to_cents_without_float_error(string|int|float|null $decimal, int $cents): void
    {
        $this->assertSame($cents, Money::decimalToCents($decimal));
    }

    public static function decimalProvider(): array
    {
        return [
            'zero' => ['0.00', 0],
            'null' => [null, 0],
            'a float-hostile value' => ['0.29', 29],
            'the classic 19.99' => ['19.99', 1999],
            'large' => ['1234567.89', 123456789],
            'integer-valued string' => ['500', 50000],
            'one decimal place' => ['1.5', 150],
            'negative' => ['-5.25', -525],
            'int input' => [500, 50000],
            'float input' => [299.0, 29900],
            'float input needing rounding' => [0.29, 29],
            'third decimal rounds half up' => ['1.005', 101],
        ];
    }

    public function test_cents_convert_to_a_two_decimal_string(): void
    {
        $this->assertSame('1234.50', Money::centsToDecimal(123450));
        $this->assertSame('0.07', Money::centsToDecimal(7));
        $this->assertSame('-0.05', Money::centsToDecimal(-5));
        $this->assertSame('0.00', Money::centsToDecimal(0));
    }

    public function test_parse_accepts_only_strict_positive_amounts(): void
    {
        $this->assertSame(123456, Money::parse('1234.56', 'ZAR')->cents);
        $this->assertSame(1200, Money::parse('12', 'ZAR')->cents);
        $this->assertSame(1210, Money::parse('12.1', 'ZAR')->cents);

        foreach (['', '1,234.56', '12.345', '-5', 'abc', '1e3', ' ', '0', '0.00', '12.', '.5'] as $bad) {
            try {
                Money::parse($bad, 'ZAR');
                $this->fail("'{$bad}' should have been rejected");
            } catch (BillingRuleViolation $e) {
                $this->assertSame('invalid_amount', $e->reason);
            }
        }
    }

    public function test_parse_can_allow_zero_for_a_free_price(): void
    {
        $this->assertSame(0, Money::parse('0', 'ZAR', allowZero: true)->cents);
        $this->assertSame(0, Money::parse('0.00', 'ZAR', allowZero: true)->cents);
        $this->expectException(BillingRuleViolation::class);
        Money::parse('-1', 'ZAR', allowZero: true);
    }

    public function test_arithmetic_is_integer_and_same_currency_only(): void
    {
        $a = new Money(1050, 'ZAR');
        $b = new Money(295, 'ZAR');

        $this->assertSame(1345, $a->add($b)->cents);
        $this->assertSame(755, $a->subtract($b)->cents);
        $this->assertTrue($a->greaterThan($b));
        $this->assertTrue((new Money(0, 'ZAR'))->isZero());

        $this->expectException(BillingRuleViolation::class);
        $a->add(new Money(100, 'USD'));
    }

    public function test_adding_across_currencies_reports_a_currency_mismatch(): void
    {
        try {
            (new Money(1, 'ZAR'))->add(new Money(1, 'USD'));
            $this->fail('expected a currency mismatch');
        } catch (BillingRuleViolation $e) {
            $this->assertSame('currency_mismatch', $e->reason);
        }
    }

    #[DataProvider('percentageProvider')]
    public function test_percentage_in_basis_points_rounds_half_up_with_integers(int $cents, int $bps, int $expected): void
    {
        $this->assertSame($expected, (new Money($cents, 'ZAR'))->percentage($bps)->cents);
    }

    public static function percentageProvider(): array
    {
        return [
            'zero rate' => [10000, 0, 0],
            '15 percent of R100' => [10000, 1500, 1500],
            '15 percent of R9.99 rounds up from 149.85 to 150' => [999, 1500, 150],
            '15 percent of R0.03 is 0.45 cents, rounds down' => [3, 1500, 0],
            '15 percent of R0.04 is 0.6 cents, rounds up' => [4, 1500, 1],
            '15.5 percent' => [20000, 1550, 3100],
        ];
    }

    public function test_formatting_uses_the_money_s_own_currency_and_thousands_separators(): void
    {
        $this->assertSame('ZAR 1,234.50', (new Money(123450, 'ZAR'))->format());
        $this->assertSame('USD 0.07', (new Money(7, 'USD'))->format());
        $this->assertSame('ZAR -12.00', (new Money(-1200, 'ZAR'))->format());
        $this->assertSame('1,234,567.89', (new Money(123456789, 'ZAR'))->formatAmount());
    }

    public function test_totals_are_grouped_per_currency_and_never_summed_across_them(): void
    {
        $totals = Money::totalsByCurrency([
            ['currency' => 'ZAR', 'cents' => 10000],
            ['currency' => 'USD', 'cents' => 5000],
            ['currency' => 'ZAR', 'cents' => 2550],
        ]);

        $this->assertSame(['USD', 'ZAR'], array_keys($totals));
        $this->assertSame(12550, $totals['ZAR']->cents);
        $this->assertSame(5000, $totals['USD']->cents);
    }

    public function test_it_serialises_for_json(): void
    {
        $this->assertSame(
            ['cents' => 1999, 'currency' => 'ZAR', 'formatted' => 'ZAR 19.99'],
            (new Money(1999, 'ZAR'))->jsonSerialize()
        );
    }
}
