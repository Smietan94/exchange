<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\WalletAlreadyExistsException;
use App\Repository\WalletRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

readonly class WalletService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
    ) {
    }

    public function createWallet(int $userId, Currency $currency): Wallet
    {
        $existing = $this->walletRepository->findByUserIdAndCurrency($userId, $currency);

        if (null !== $existing) {
            throw new WalletAlreadyExistsException($userId, $currency);
        }

        $wallet = Wallet::create($userId, $currency);
        try {
            $this->walletRepository->save($wallet);
        } catch (UniqueConstraintViolationException $exception) {
            // The pre-check can race with another create request. Translate only
            // a unique violation that left this user's active currency wallet in place.
            if (null === $this->walletRepository->findByUserIdAndCurrency($userId, $currency)) {
                throw $exception;
            }

            throw new WalletAlreadyExistsException($userId, $currency);
        }

        return $wallet;
    }

    public function deleteWallet(int $userId, int $walletId): void
    {
        $this->walletRepository->archiveOwnedWallet($walletId, $userId);
    }
}
