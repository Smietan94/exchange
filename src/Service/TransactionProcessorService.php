<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;

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
        $this->completeFromStatus($transaction, TransactionStatus::PENDING);
    }

    public function approveFraudReview(Transaction $transaction): void
    {
        $this->completeFromStatus($transaction, TransactionStatus::FRAUD_REVIEW);
    }

    public function rejectFraudReview(Transaction $transaction): void
    {
        if (null === $transaction->getId() || TransactionStatus::FRAUD_REVIEW !== $transaction->getStatus()) {
            return;
        }

        $checkedAt = new DateTimeImmutable();
        $rejected = $this->transactionRepository->transactional(function () use ($transaction, $checkedAt): bool {
            $sourceWallet = $this->walletRepository->findById($transaction->getFromWalletId());
            if (null === $sourceWallet) {
                return false;
            }

            if (!$this->transactionRepository->transition(
                $transaction,
                TransactionStatus::FRAUD_REVIEW,
                TransactionStatus::REJECTED,
                $checkedAt,
            )) {
                return false;
            }

            $this->walletRepository->addToBalance($transaction->getFromWalletId(), $transaction->getFromAmount());

            return true;
        });

        if ($rejected) {
            $transaction->setStatus(TransactionStatus::REJECTED);
            $transaction->setAntiFraudCheckedAt($checkedAt);
        }
    }

    private function completeFromStatus(Transaction $transaction, TransactionStatus $expectedStatus): void
    {
        if (null === $transaction->getId() || $expectedStatus !== $transaction->getStatus()) {
            return;
        }

        $checkedAt = TransactionStatus::FRAUD_REVIEW === $expectedStatus
            ? new DateTimeImmutable()
            : null;

        $completedStatus = $this->transactionRepository->transactional(function () use ($transaction, $expectedStatus, $checkedAt): ?TransactionStatus {
            $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());
            $toWallet = $this->walletRepository->findById($transaction->getToWalletId());

            if (null === $fromWallet || null === $toWallet) {
                if (!$this->transactionRepository->transition(
                    $transaction,
                    $expectedStatus,
                    TransactionStatus::REJECTED,
                    $checkedAt,
                )) {
                    return null;
                }

                if (null !== $fromWallet) {
                    $this->walletRepository->addToBalance($transaction->getFromWalletId(), $transaction->getFromAmount());
                }

                return TransactionStatus::REJECTED;
            }

            if (!$this->transactionRepository->transition(
                $transaction,
                $expectedStatus,
                TransactionStatus::COMPLETED,
                $checkedAt,
            )) {
                return null;
            }

            $this->walletRepository->addToBalance($transaction->getToWalletId(), $transaction->getToAmount());
            $this->companyWalletRepository->addToBalance($transaction->getToCurrency(), $transaction->getSpread());

            return TransactionStatus::COMPLETED;
        });

        if (null !== $completedStatus) {
            $transaction->setStatus($completedStatus);
            if (null !== $checkedAt) {
                $transaction->setAntiFraudCheckedAt($checkedAt);
            }
        }
    }
}
