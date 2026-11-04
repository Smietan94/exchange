<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\TransactionProcessorService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[AllowMockObjectsWithoutExpectations]
class TransactionProcessorServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private CompanyWalletRepositoryInterface $companyWalletRepository;
    private TransactionProcessorService $processor;
    private array $databaseState = [];

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->companyWalletRepository = $this->createMock(CompanyWalletRepositoryInterface::class);
        $this->transactionRepository->method('transactional')->willReturnCallback(function (callable $operation): mixed {
            $snapshot = $this->databaseState;

            try {
                return $operation();
            } catch (Throwable $exception) {
                $this->databaseState = $snapshot;
                throw $exception;
            }
        });
        $this->processor = new TransactionProcessorService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->companyWalletRepository,
        );
    }

    public function testCompleteUpdatesDestinationAndSpreadWithoutDebitingReservedSourceAgain(): void
    {
        $source = Wallet::create(1, Currency::PLN);
        $source->setBalance(400.0);
        $destination = Wallet::create(1, Currency::EUR);
        $destination->setBalance(100.0);
        $transaction = $this->makeTransaction(false);
        $this->walletRepository->method('findById')->willReturnMap([[1, $source], [2, $destination]]);
        $this->transactionRepository->expects(self::once())->method('transition')
            ->with($transaction, TransactionStatus::PENDING, TransactionStatus::COMPLETED, null)
            ->willReturn(true);
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(2, '25.0000');
        $this->walletRepository->expects(self::never())->method('reserveBalance');
        $this->companyWalletRepository->expects(self::once())->method('addToBalance')->with(Currency::EUR, '0.5000');

        $this->processor->complete($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteSetsAntiFraudCheckedAtWhenFraudReviewIsApproved(): void
    {
        $transaction = $this->makeTransaction(true);
        $this->walletRepository->method('findById')->willReturnMap([
            [1, Wallet::create(1, Currency::PLN)],
            [2, Wallet::create(1, Currency::EUR)],
        ]);
        $this->transactionRepository->expects(self::once())->method('transition')
            ->with(
                $transaction,
                TransactionStatus::FRAUD_REVIEW,
                TransactionStatus::COMPLETED,
                $this->isInstanceOf(DateTimeImmutable::class),
            )->willReturn(true);
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(2, '25.0000');
        $this->walletRepository->expects(self::never())->method('reserveBalance');
        $this->companyWalletRepository->expects(self::once())->method('addToBalance')->with(Currency::EUR, '0.5000');

        $this->processor->approveFraudReview($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteRejectsWhenFromWalletNotFound(): void
    {
        $transaction = $this->makeTransaction(false);
        $this->walletRepository->method('findById')->willReturnMap([
            [1, null],
            [2, Wallet::create(1, Currency::EUR)],
        ]);
        $this->transactionRepository->expects(self::once())->method('transition')
            ->with($transaction, TransactionStatus::PENDING, TransactionStatus::REJECTED, null)
            ->willReturn(true);
        $this->walletRepository->expects(self::never())->method('addToBalance');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->processor->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    public function testCompleteReturnsReservedFundsWhenToWalletNotFound(): void
    {
        $transaction = $this->makeTransaction(false);
        $source = Wallet::create(1, Currency::PLN);
        $this->walletRepository->method('findById')->willReturnMap([[1, $source], [2, null]]);
        $this->transactionRepository->expects(self::once())->method('transition')
            ->with($transaction, TransactionStatus::PENDING, TransactionStatus::REJECTED, null)
            ->willReturn(true);
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(1, '100.0000');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->processor->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    public function testRejectSetsRejectedStatusAndReturnsReservedAmount(): void
    {
        $transaction = $this->makeTransaction(true);
        $this->walletRepository->method('findById')->willReturn(Wallet::create(1, Currency::PLN));
        $this->transactionRepository->expects(self::once())->method('transition')
            ->with(
                $transaction,
                TransactionStatus::FRAUD_REVIEW,
                TransactionStatus::REJECTED,
                $this->isInstanceOf(DateTimeImmutable::class),
            )->willReturn(true);
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(1, '100.0000');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->processor->rejectFraudReview($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testRepeatedFraudApprovalDoesNotCreditDestinationOrSpreadAgain(): void
    {
        $transaction = $this->makeTransaction(true);
        $this->walletRepository->method('findById')->willReturnMap([
            [1, Wallet::create(1, Currency::PLN)],
            [2, Wallet::create(1, Currency::EUR)],
        ]);
        $this->transactionRepository->expects(self::once())->method('transition')->willReturn(true);
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(2, '25.0000');
        $this->companyWalletRepository->expects(self::once())->method('addToBalance')->with(Currency::EUR, '0.5000');

        $this->processor->approveFraudReview($transaction);
        $this->processor->approveFraudReview($transaction);
    }

    public function testRepeatedFraudRejectionDoesNotReturnReservedAmountAgain(): void
    {
        $transaction = $this->makeTransaction(true);
        $this->walletRepository->expects(self::once())->method('findById')
            ->with(1)->willReturn(Wallet::create(1, Currency::PLN));
        $this->transactionRepository->expects(self::once())->method('transition')->willReturn(true);
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(1, '100.0000');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->processor->rejectFraudReview($transaction);
        $this->processor->rejectFraudReview($transaction);
    }

    public function testFraudDecisionDoesNothingForNonFraudReviewStatus(): void
    {
        $transaction = $this->makeTransaction(false);
        $this->transactionRepository->expects(self::never())->method('transactional');
        $this->transactionRepository->expects(self::never())->method('transition');
        $this->walletRepository->expects(self::never())->method('findById');
        $this->walletRepository->expects(self::never())->method('addToBalance');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->processor->approveFraudReview($transaction);
        $this->processor->rejectFraudReview($transaction);

        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testFailedApprovalRollsBackAllLedgerChangesAndLeavesReviewOpen(): void
    {
        $transaction = $this->makeTransaction(true);
        $this->databaseState = [
            'status' => TransactionStatus::FRAUD_REVIEW,
            'checkedAt' => null,
            'source' => 50.0,
            'destination' => 100.0,
            'company' => 2.0,
        ];
        $source = Wallet::create(1, Currency::PLN);
        $destination = Wallet::create(1, Currency::EUR);
        $this->walletRepository->method('findById')->willReturnMap([[1, $source], [2, $destination]]);
        $this->transactionRepository->method('transition')->willReturnCallback(
            function (
                Transaction $transaction,
                TransactionStatus $expected,
                TransactionStatus $next,
                ?DateTimeImmutable $checkedAt,
            ): bool {
                if ($this->databaseState['status'] !== $expected) {
                    return false;
                }
                $this->databaseState['status'] = $next;
                $this->databaseState['checkedAt'] = $checkedAt;

                return true;
            },
        );
        $this->walletRepository->method('addToBalance')->willReturnCallback(function (int $walletId, string $amount): void {
            if (2 === $walletId) {
                $this->databaseState['destination'] += (float) $amount;
            }
        });
        $this->companyWalletRepository->expects(self::once())->method('addToBalance')
            ->willThrowException(new RuntimeException('Company wallet write failed.'));

        $this->expectException(RuntimeException::class);
        try {
            $this->processor->approveFraudReview($transaction);
        } finally {
            self::assertSame(TransactionStatus::FRAUD_REVIEW, $this->databaseState['status']);
            self::assertNull($this->databaseState['checkedAt']);
            self::assertSame(50.0, $this->databaseState['source']);
            self::assertSame(100.0, $this->databaseState['destination']);
            self::assertSame(2.0, $this->databaseState['company']);
            self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
            self::assertNull($transaction->getAntiFraudCheckedAt());
        }
    }

    private function makeTransaction(bool $requiresAntiFraudCheck): Transaction
    {
        return Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.0000',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.5000',
            exchangeRate: '0.250000',
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
        );
    }
}
