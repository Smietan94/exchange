<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\WalletRepositoryInterface;
use Brick\Money\Money;
use DateTimeImmutable;

readonly class DepositService
{
    public const string MAX_AMOUNT = '10000';

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
    ) {
    }

    public function deposit(int $userId, int $walletId, string $amount): Wallet
    {
        $wallet = $this->walletRepository->findById($walletId);
        if (null === $wallet || $wallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($walletId);
        }

        if ($wallet->isBlocked()) {
            throw new WalletBlockedException($walletId);
        }

        $depositAmount = MoneyAmount::parse($amount, $wallet->getCurrency());
        if ($depositAmount->isGreaterThan(Money::of(self::MAX_AMOUNT, $wallet->getCurrency()->value))) {
            throw new \InvalidArgumentException('Deposit amount cannot exceed '.self::MAX_AMOUNT.'.');
        }

        $wallet->setBalance($wallet->getBalance()->plus($depositAmount));
        $wallet->setLastActivityAt(new DateTimeImmutable());
        $this->walletRepository->save($wallet);

        return $wallet;
    }
}
