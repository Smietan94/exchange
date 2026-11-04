<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Enum\Currency;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use InvalidArgumentException;

readonly class TransferService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
    ) {
    }

    public function transfer(
        int $userId,
        int $fromWalletId,
        int $toWalletId,
        string $fromAmount,
    ): Transaction {
        if ($fromWalletId === $toWalletId) {
            throw new InvalidArgumentException('Source and destination wallets must be different.');
        }
        if (!is_numeric($fromAmount) || !is_finite((float) $fromAmount) || (float) $fromAmount <= 0) {
            throw new InvalidArgumentException('Transfer amount must be positive.');
        }

        $fromWallet = $this->walletRepository->findById($fromWalletId);
        if (null === $fromWallet || $fromWallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($fromWalletId);
        }

        $toWallet = $this->walletRepository->findById($toWalletId);
        if (null === $toWallet || $toWallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($toWalletId);
        }

        if ((float) $fromAmount > $fromWallet->getBalance()) {
            throw new InvalidArgumentException('Insufficient wallet balance.');
        }

        $fromCurrency = $fromWallet->getCurrency();
        $toCurrency = $toWallet->getCurrency();

        $exchangeRate = $this->exchangeRateService->getExchangeRateBetween($fromCurrency, $toCurrency);
        $amountInEur = (float) $fromAmount * $this->exchangeRateService->getExchangeRateBetween($fromCurrency, Currency::EUR);
        $rawToAmount = (float) $fromAmount * $exchangeRate;
        $spread = $this->spreadService->calculateSpread($rawToAmount, $fromCurrency, $toCurrency);
        $toAmount = $rawToAmount - (float) $spread;

        $toAmountFormatted = number_format($toAmount, 4, '.', '');

        $transaction = Transaction::create(
            fromWalletId: $fromWalletId,
            toWalletId: $toWalletId,
            fromAmount: $fromAmount,
            toAmount: $toAmountFormatted,
            fromCurrency: $fromCurrency,
            toCurrency: $toCurrency,
            spread: $spread,
            exchangeRate: number_format($exchangeRate, 6, '.', ''),
            requiresAntiFraudCheck: $amountInEur > 15_000,
        );

        $this->transactionRepository->transactional(function () use ($fromWalletId, $fromAmount, $transaction): void {
            if (!$this->walletRepository->reserveBalance($fromWalletId, $fromAmount)) {
                throw new InvalidArgumentException('Insufficient wallet balance.');
            }
            $this->transactionRepository->save($transaction);
        });

        return $transaction;
    }
}
