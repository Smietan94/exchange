<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Brick\Money\Context\DefaultContext;
use Brick\Money\Currency as MoneyCurrency;
use Brick\Money\Money;
use Brick\Money\RationalMoney;

class SpreadService
{
    private const array LIQUIDITY_SCORE = [
        Currency::USD->value => '1',
        Currency::EUR->value => '0.95',
        Currency::GBP->value => '0.85',
        Currency::CHF->value => '0.80',
        Currency::JPY->value => '0.75',
        Currency::PLN->value => '0.55',
        Currency::HUF->value => '0.40',
    ];

    private const string BASE_SPREAD_PERCENT = '0.5';

    public function calculateSpread(
        Money $price,
        Currency $fromCurrency,
        Currency $toCurrency,
    ): Money {
        // `price` is the already rounded gross transaction amount in the target currency.
        // Keep the formula rational until one explicit HALF_UP rounding to its minor unit.
        if (!$price->getCurrency()->isEqualTo($toCurrency->value)) {
            throw new \InvalidArgumentException('Spread price currency must match destination currency.');
        }

        if ($fromCurrency === $toCurrency) {
            return Money::zero($toCurrency->value);
        }

        $fromLiquidity = BigRational::of(self::LIQUIDITY_SCORE[$fromCurrency->value]);
        $toLiquidity = BigRational::of(self::LIQUIDITY_SCORE[$toCurrency->value]);

        $pairLiquidity = $fromLiquidity->plus($toLiquidity)->dividedBy(2);
        $spreadRate = BigRational::of(self::BASE_SPREAD_PERCENT)
            ->dividedBy($pairLiquidity)
            ->dividedBy(100);
        $spreadAmount = $price->toRational()->multipliedBy($spreadRate);

        return (new RationalMoney($spreadAmount->getAmount(), MoneyCurrency::of($toCurrency->value)))
            ->toContext(new DefaultContext(), RoundingMode::HalfUp);
    }
}
