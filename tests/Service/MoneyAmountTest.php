<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Currency;
use App\Service\MoneyAmount;
use Brick\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyAmountTest extends TestCase
{
    public function testParsesAndFormatsCurrencyPrecisionFromBrickMetadata(): void
    {
        self::assertSame('12', MoneyAmount::format(MoneyAmount::parse('12', Currency::JPY)));
        self::assertSame('12.34', MoneyAmount::format(MoneyAmount::parse('12.34', Currency::HUF)));
        self::assertSame('12.34', MoneyAmount::format(MoneyAmount::parse('12.34', Currency::EUR)));
    }

    #[DataProvider('unsupportedPrecisionProvider')]
    public function testRejectsMoreFractionDigitsThanCurrencyAllows(string $amount, Currency $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        MoneyAmount::parse($amount, $currency);
    }

    public static function unsupportedPrecisionProvider(): array
    {
        return [
            'JPY fractions' => ['1.1', Currency::JPY],
            'HUF three decimals' => ['1.234', Currency::HUF],
            'EUR three decimals' => ['1.001', Currency::EUR],
            'EUR excessive trailing zero' => ['1.2300', Currency::EUR],
        ];
    }

    public function testMoneyAdditionAndSubtractionRemainExact(): void
    {
        $result = Money::of('90071992547409.11', 'EUR')
            ->plus(Money::of('0.89', 'EUR'))
            ->minus(Money::of('0.01', 'EUR'));

        self::assertSame('90071992547409.99', MoneyAmount::format($result));
    }

    public function testDatabaseValuesWithLegacyZeroPaddingRoundTripExactly(): void
    {
        self::assertSame('10.25', MoneyAmount::format(MoneyAmount::fromDatabase('10.2500', Currency::EUR)));
        self::assertSame('100', MoneyAmount::format(MoneyAmount::fromDatabase('100.0000', Currency::JPY)));
    }

    public function testRejectsNonZeroUnsupportedDatabasePrecision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MoneyAmount::fromDatabase('1.0010', Currency::EUR);
    }

    public function testExchangeAndSpreadUseHalfUpAtCurrencyMinorUnit(): void
    {
        $exchangeRates = new \App\Service\ExchangeRateService();
        $spread = new \App\Service\SpreadService();

        // 1 PLN = 1 / 4.2389 EUR. The exact result lies between euro cents.
        $converted = $exchangeRates->exchange(Money::of('1.00', 'PLN'), Currency::EUR);
        self::assertSame('0.24', MoneyAmount::format($converted));
        self::assertSame('185', MoneyAmount::format($exchangeRates->exchange(Money::of('1', 'EUR'), Currency::JPY)));
        self::assertSame('359.23', MoneyAmount::format($exchangeRates->exchange(Money::of('1.00', 'EUR'), Currency::HUF)));

        // The unrounded spread is retained as a rational until it is rounded once to JPY's unit (1 yen).
        $jpySpread = $spread->calculateSpread(Money::of('1', 'JPY'), Currency::EUR, Currency::JPY);
        self::assertSame('0', MoneyAmount::format($jpySpread));
    }
}
