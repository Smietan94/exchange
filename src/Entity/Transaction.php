<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use App\Enum\TransactionStatus;
use Brick\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

class Transaction
{
    public function __construct(
        private ?int $id,
        private readonly int $fromWalletId,
        private readonly int $toWalletId,
        private readonly Money $fromAmount,
        private readonly Money $toAmount,
        private readonly Currency $fromCurrency,
        private readonly Currency $toCurrency,
        private readonly Money $spread,
        private readonly string $exchangeRate,
        private TransactionStatus $status,
        private readonly bool $requiresAntiFraudCheck,
        private ?DateTimeImmutable $antiFraudCheckedAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if (!$fromAmount->getCurrency()->isEqualTo($fromCurrency->value)) {
            throw new InvalidArgumentException('Source amount currency must match source currency.');
        }
        if (!$toAmount->getCurrency()->isEqualTo($toCurrency->value) || !$spread->getCurrency()->isEqualTo($toCurrency->value)) {
            throw new InvalidArgumentException('Destination amount and spread currencies must match destination currency.');
        }
    }

    public static function create(
        int $fromWalletId,
        int $toWalletId,
        Money $fromAmount,
        Money $toAmount,
        Currency $fromCurrency,
        Currency $toCurrency,
        Money $spread,
        string $exchangeRate,
        bool $requiresAntiFraudCheck,
    ): self {
        return new self(
            id: null,
            fromWalletId: $fromWalletId,
            toWalletId: $toWalletId,
            fromAmount: $fromAmount,
            toAmount: $toAmount,
            fromCurrency: $fromCurrency,
            toCurrency: $toCurrency,
            spread: $spread,
            exchangeRate: $exchangeRate,
            status: $requiresAntiFraudCheck
                ? TransactionStatus::FRAUD_REVIEW
                : TransactionStatus::PENDING,
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromWalletId(): int
    {
        return $this->fromWalletId;
    }

    public function getToWalletId(): int
    {
        return $this->toWalletId;
    }

    public function getFromAmount(): Money
    {
        return $this->fromAmount;
    }

    public function getToAmount(): Money
    {
        return $this->toAmount;
    }

    public function getFromCurrency(): Currency
    {
        return $this->fromCurrency;
    }

    public function getToCurrency(): Currency
    {
        return $this->toCurrency;
    }

    public function getSpread(): Money
    {
        return $this->spread;
    }

    public function getExchangeRate(): string
    {
        return $this->exchangeRate;
    }

    public function getStatus(): TransactionStatus
    {
        return $this->status;
    }

    public function requiresAntiFraudCheck(): bool
    {
        return $this->requiresAntiFraudCheck;
    }

    public function getAntiFraudCheckedAt(): ?DateTimeImmutable
    {
        return $this->antiFraudCheckedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setStatus(TransactionStatus $status): void
    {
        $this->status = $status;
    }

    public function setAntiFraudCheckedAt(?DateTimeImmutable $antiFraudCheckedAt): void
    {
        $this->antiFraudCheckedAt = $antiFraudCheckedAt;
    }
}
