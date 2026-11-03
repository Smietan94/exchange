<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use Brick\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

class CompanyWallet
{
    public function __construct(
        private ?int $id,
        private readonly Currency $currency,
        private Money $balance,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        if (!$balance->getCurrency()->isEqualTo($currency->value)) {
            throw new InvalidArgumentException('Company wallet balance currency must match wallet currency.');
        }
    }

    public static function create(Currency $currency): self
    {
        $now = new DateTimeImmutable();

        return new self(
            id: null,
            currency: $currency,
            balance: Money::zero($currency->value),
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getBalance(): Money
    {
        return $this->balance;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setBalance(Money $balance): void
    {
        if (!$balance->getCurrency()->isEqualTo($this->currency->value)) {
            throw new InvalidArgumentException('Company wallet balance currency must match wallet currency.');
        }

        $this->balance = $balance;
    }
}
