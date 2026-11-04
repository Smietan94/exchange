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
    private const string EUR_ANTI_FRAUD_THRESHOLD = '15000';

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
        private TransactionProcessorService $transactionProcessorService,
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
        $normalizedAmount = self::normalizeAmount($fromAmount);
        if (null === $normalizedAmount) {
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

        try {
            $sourceAmount = MoneyAmount::parse($normalizedAmount, $fromWallet->getCurrency());
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Transfer amount precision is not supported by the source currency.', previous: $exception);
        }

        if ($sourceAmount->isGreaterThan($fromWallet->getBalance())) {
            throw new InvalidArgumentException('Insufficient wallet balance.');
        }

        $fromCurrency = $fromWallet->getCurrency();
        $toCurrency = $toWallet->getCurrency();

        $exchangeRate = $this->exchangeRateService->getExchangeRateBetween($fromCurrency, $toCurrency);
        // Rounding policy: round converted gross once to the target currency unit,
        // calculate and round spread from that gross, then derive net by subtraction.
        $grossToAmount = $this->exchangeRateService->exchange($sourceAmount, $toCurrency);
        $spread = $this->spreadService->calculateSpread($grossToAmount, $fromCurrency, $toCurrency);
        $toAmount = $grossToAmount->minus($spread);
        if (!$toAmount->isPositive()) {
            throw new InvalidArgumentException('Transfer amount is too small to produce a positive destination amount after spread.');
        }

        $transaction = Transaction::create(
            fromWalletId: $fromWalletId,
            toWalletId: $toWalletId,
            fromAmount: $sourceAmount,
            toAmount: $toAmount,
            fromCurrency: $fromCurrency,
            toCurrency: $toCurrency,
            spread: $spread,
            exchangeRate: $exchangeRate,
            requiresAntiFraudCheck: $this->exchangeRateService->exceedsEurThreshold($sourceAmount, self::EUR_ANTI_FRAUD_THRESHOLD),
        );

        $this->transactionRepository->transactional(function () use ($fromWalletId, $toWalletId, $sourceAmount, $transaction): void {
            if (!$this->walletRepository->lockActiveWallets($fromWalletId, $toWalletId)) {
                if (null === $this->walletRepository->findById($fromWalletId)) {
                    throw new WalletNotFoundException($fromWalletId);
                }

                throw new WalletNotFoundException($toWalletId);
            }

            if (!$this->walletRepository->reserveBalance($fromWalletId, $sourceAmount)) {
                throw new InvalidArgumentException('Insufficient wallet balance.');
            }
            $this->transactionRepository->save($transaction);

            if (!$transaction->requiresAntiFraudCheck()) {
                $this->transactionProcessorService->complete($transaction);
            }
        });

        return $transaction;
    }

    public static function normalizeAmount(mixed $amount): ?string
    {
        return MoneyAmount::normalizeInput($amount);
    }
}
