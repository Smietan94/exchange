<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;

final readonly class TransactionProcessorService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private CompanyWalletRepositoryInterface $companyWalletRepository,
    ) {
    }

    public function complete(Transaction $transaction): void
    {
        $this->transactionRepository->transactional(function () use ($transaction): void {
            $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());
            $toWallet = $this->walletRepository->findById($transaction->getToWalletId());

            if (null === $fromWallet || null === $toWallet) {
                $this->rejectWithinTransaction($transaction, null !== $fromWallet);

                return;
            }

            if (!$this->transactionRepository->transition($transaction, TransactionStatus::COMPLETED)) {
                return;
            }

            $this->walletRepository->addToBalance($transaction->getToWalletId(), $transaction->getToAmount());
            $this->companyWalletRepository->addToBalance($transaction->getToCurrency(), $transaction->getSpread());
        });
    }

    public function reject(Transaction $transaction): void
    {
        $this->transactionRepository->transactional(function () use ($transaction): void {
            $this->rejectWithinTransaction($transaction, true);
        });
    }

    private function rejectWithinTransaction(Transaction $transaction, bool $returnFunds): void
    {
        if (!$this->transactionRepository->transition($transaction, TransactionStatus::REJECTED)) {
            return;
        }

        if ($returnFunds) {
            $this->walletRepository->addToBalance($transaction->getFromWalletId(), $transaction->getFromAmount());
        }
    }
}
