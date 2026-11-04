<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Brick\Money\Currency as MoneyCurrency;
use Brick\Money\Money;
use Brick\Money\RationalMoney;
use Brick\Money\Context\DefaultContext;

class ExchangeRateService
{
    private const int RECORDED_RATE_SCALE = 20;

    public function getExchangeRate(Currency $currency): string
    {
        return match ($currency) {
            Currency::PLN => '1',
            Currency::EUR => '4.2389',
            Currency::USD => '3.6467',
            Currency::GBP => '4.881',
            Currency::JPY => '0.0229',
            Currency::CHF => '4.6347',
            Currency::HUF => '0.0118',
        };
    }

    public function getExchangeRateBetween(Currency $from, Currency $to): string
    {
        return BigRational::of($this->getExchangeRate($from))
            ->dividedBy($this->getExchangeRate($to))
            ->toScale(self::RECORDED_RATE_SCALE, RoundingMode::HalfUp)
            ->toString();
    }

    public function exchange(Money $amount, Currency $to): Money
    {
        $fromCurrency = Currency::from($amount->getCurrency()->getCurrencyCode());
        // Calculate from the same high-precision rate stored on the transaction.
        return $this->exchangeAsRational($amount, $to, $this->getExchangeRateBetween($fromCurrency, $to))
            ->toContext(new DefaultContext(), RoundingMode::HalfUp);
    }

    public function exceedsEurThreshold(Money $amount, string $threshold): bool
    {
        $fromCurrency = Currency::from($amount->getCurrency()->getCurrencyCode());
        $exactRate = BigRational::of($this->getExchangeRate($fromCurrency))
            ->dividedBy($this->getExchangeRate(Currency::EUR));

        return $amount->toRational()->multipliedBy($exactRate)->isGreaterThan($threshold);
    }

    private function exchangeAsRational(Money $amount, Currency $to, string $exchangeRate): RationalMoney
    {
        $rationalAmount = $amount->toRational()->multipliedBy(BigRational::of($exchangeRate));

        return new RationalMoney($rationalAmount->getAmount(), MoneyCurrency::of($to->value));
    }
}
