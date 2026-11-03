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
            ->toScale(6, RoundingMode::HalfUp)
            ->toString();
    }

    public function exchange(Money $amount, Currency $to): Money
    {
        return $this->exchangeAsRational($amount, $to)->toContext(new DefaultContext(), RoundingMode::HalfUp);
    }

    public function exceedsEurThreshold(Money $amount, string $threshold): bool
    {
        return $this->exchangeAsRational($amount, Currency::EUR)->isGreaterThan($threshold);
    }

    private function exchangeAsRational(Money $amount, Currency $to): RationalMoney
    {
        $fromCurrency = Currency::from($amount->getCurrency()->getCurrencyCode());
        $exchangeRate = BigRational::of($this->getExchangeRate($fromCurrency))
            ->dividedBy($this->getExchangeRate($to));
        $rationalAmount = $amount->toRational()->multipliedBy($exchangeRate);

        return new RationalMoney($rationalAmount->getAmount(), MoneyCurrency::of($to->value));
    }
}
