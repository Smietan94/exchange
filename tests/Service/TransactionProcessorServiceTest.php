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
use Brick\Money\Money;
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
        $source->setBalance(Money::of('400.00', 'PLN'));
        $destination = Wallet::create(1, Currency::EUR);
        $destination->setBalance(Money::of('100.00', 'EUR'));
        $transaction = $this->makeTransaction(false);
        $this->walletRepository->method('findById')->willReturnMap([[1, $source], [2, $destination]]);
        $this->transactionRepository->expects(self::once())->method('transition')
            ->with($transaction, TransactionStatus::PENDING, TransactionStatus::COMPLETED, null)
            ->willReturn(true);
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(2, Money::of('25.00', 'EUR'));
        $this->walletRepository->expects(self::never())->method('reserveBalance');
        $this->companyWalletRepository->expects(self::once())->method('addToBalance')->with(Currency::EUR, Money::of('0.50', 'EUR'));

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
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(2, Money::of('25.00', 'EUR'));
        $this->walletRepository->expects(self::never())->method('reserveBalance');
        $this->companyWalletRepository->expects(self::once())->method('addToBalance')->with(Currency::EUR, Money::of('0.50', 'EUR'));

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
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(1, Money::of('100.00', 'PLN'));
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
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(1, Money::of('100.00', 'PLN'));
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
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(2, Money::of('25.00', 'EUR'));
        $this->companyWalletRepository->expects(self::once())->method('addToBalance')->with(Currency::EUR, Money::of('0.50', 'EUR'));

        $this->processor->approveFraudReview($transaction);
        $this->processor->approveFraudReview($transaction);
    }

    public function testRepeatedFraudRejectionDoesNotReturnReservedAmountAgain(): void
    {
        $transaction = $this->makeTransaction(true);
        $this->walletRepository->expects(self::once())->method('findById')
            ->with(1)->willReturn(Wallet::create(1, Currency::PLN));
        $this->transactionRepository->expects(self::once())->method('transition')->willReturn(true);
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(1, Money::of('100.00', 'PLN'));
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
            'source' => '50.00',
            'destination' => '100.00',
            'company' => '2.00',
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
        $this->walletRepository->method('addToBalance')->willReturnCallback(function (int $walletId, Money $amount): void {
            if (2 === $walletId) {
                $this->databaseState['destination'] = Money::of($this->databaseState['destination'], 'EUR')->plus($amount)->getAmount()->toString();
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
            self::assertSame('50.00', $this->databaseState['source']);
            self::assertSame('100.00', $this->databaseState['destination']);
            self::assertSame('2.00', $this->databaseState['company']);
            self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
            self::assertNull($transaction->getAntiFraudCheckedAt());
        }
    }

    private function makeTransaction(bool $requiresAntiFraudCheck): Transaction
    {
        return Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('100.00', 'PLN'),
            toAmount: Money::of('25.00', 'EUR'),
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: Money::of('0.50', 'EUR'),
            exchangeRate: '0.250000',
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
        );
    }
}
