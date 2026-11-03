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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransactionProcessorServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private CompanyWalletRepositoryInterface $companyWalletRepository;
    private TransactionProcessorService $processor;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->companyWalletRepository = $this->createMock(CompanyWalletRepositoryInterface::class);
        $this->transactionRepository->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $this->processor = new TransactionProcessorService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->companyWalletRepository,
        );
    }

    public function testCompleteCreditsDestinationAndSpreadOnceAndDoesNotDeductSourceAgain(): void
    {
        $source = Wallet::create(1, Currency::PLN);
        $destination = Wallet::create(1, Currency::EUR);
        $transaction = $this->makeTransaction(false);
        $this->walletRepository->method('findById')->willReturnMap([[1, $source], [2, $destination]]);
        $this->transactionRepository->expects(self::once())->method('transition')
            ->with($transaction, TransactionStatus::COMPLETED)->willReturnCallback(
                static function (Transaction $transaction, TransactionStatus $status): bool {
                    $transaction->setStatus($status);

                    return true;
                },
            );
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(2, '25.0000');
        $this->companyWalletRepository->expects(self::once())->method('addToBalance')->with(Currency::EUR, '0.5000');

        $this->processor->complete($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testRepeatedCompletionDoesNotCreditAnyBalanceOrSpreadAgain(): void
    {
        $transaction = $this->makeTransaction(false);
        $wallets = [Wallet::create(1, Currency::PLN), Wallet::create(1, Currency::EUR)];
        $this->walletRepository->method('findById')->willReturnMap([[1, $wallets[0]], [2, $wallets[1]]]);
        $processed = false;
        $this->transactionRepository->expects(self::exactly(2))->method('transition')
            ->willReturnCallback(static function (Transaction $transaction, TransactionStatus $status) use (&$processed): bool {
                if ($processed) {
                    return false;
                }
                $processed = true;
                $transaction->setStatus($status);

                return true;
            });
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(2, '25.0000');
        $this->companyWalletRepository->expects(self::once())->method('addToBalance')->with(Currency::EUR, '0.5000');

        $this->processor->complete($transaction);
        $this->processor->complete($transaction);
    }

    public function testRejectReturnsReservedAmountWithoutCreditingDestinationOrCompany(): void
    {
        $transaction = $this->makeTransaction(true);
        $this->transactionRepository->expects(self::once())->method('transition')
            ->with($transaction, TransactionStatus::REJECTED)->willReturnCallback(
                static function (Transaction $transaction, TransactionStatus $status): bool {
                    $transaction->setStatus($status);
                    $transaction->setAntiFraudCheckedAt(new \DateTimeImmutable());

                    return true;
                },
            );
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(1, '100.0000');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->processor->reject($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testRepeatedRejectionDoesNotReturnReservedAmountAgain(): void
    {
        $transaction = $this->makeTransaction(false);
        $processed = false;
        $this->transactionRepository->expects(self::exactly(2))->method('transition')
            ->willReturnCallback(static function (Transaction $transaction, TransactionStatus $status) use (&$processed): bool {
                if ($processed) {
                    return false;
                }
                $processed = true;
                $transaction->setStatus($status);

                return true;
            });
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(1, '100.0000');

        $this->processor->reject($transaction);
        $this->processor->reject($transaction);
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
