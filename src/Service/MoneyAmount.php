<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency as AppCurrency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Currency as MoneyCurrency;
use Brick\Money\Money;
use InvalidArgumentException;

final class MoneyAmount
{
    public static function normalizeInput(mixed $amount): ?string
    {
        if (!is_string($amount) || 1 !== preg_match('/\A(?:0|[1-9]\d{0,10})(?:\.\d{1,4})?\z/', $amount)) {
            return null;
        }

        try {
            return BigDecimal::of($amount)->isPositive() ? $amount : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function parse(mixed $amount, AppCurrency $currency): Money
    {
        $normalizedAmount = self::normalizeInput($amount);
        if (null === $normalizedAmount) {
            throw new InvalidArgumentException('Amount must be a positive decimal number.');
        }

        $fractionDigits = str_contains($normalizedAmount, '.')
            ? strlen(substr(strrchr($normalizedAmount, '.'), 1))
            : 0;
        if ($fractionDigits > MoneyCurrency::of($currency->value)->getDefaultFractionDigits()) {
            throw new InvalidArgumentException('Amount precision is not supported by the currency.');
        }

        try {
            return Money::of($normalizedAmount, $currency->value, roundingMode: RoundingMode::Unnecessary);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Amount precision is not supported by the currency.', previous: $exception);
        }
    }

    public static function fromDatabase(string $amount, AppCurrency $currency): Money
    {
        try {
            return Money::of($amount, $currency->value, roundingMode: RoundingMode::Unnecessary);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Stored amount precision is not supported by the currency.', previous: $exception);
        }
    }

    public static function format(Money $money): string
    {
        return $money->getAmount()->toScale(
            $money->getCurrency()->getDefaultFractionDigits(),
            RoundingMode::Unnecessary,
        )->toString();
    }
}
