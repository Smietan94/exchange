<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

#[AllowMockObjectsWithoutExpectations]
class TransferServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private ExchangeRateService $exchangeRateService;
    private SpreadService $spreadService;
    private TransferService $transferService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->exchangeRateService = $this->createMock(ExchangeRateService::class);
        $this->spreadService = $this->createMock(SpreadService::class);

        $this->transferService = new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->exchangeRateService,
            $this->spreadService,
        );
        $this->transactionRepository->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
    }

    public function testTransferSuccessfully(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet
            ->method('getCurrency')
            ->willReturn(Currency::PLN);
        $fromWallet->method('getBalance')->willReturn(5000.0);
        $fromWallet
            ->method('getUserId')
            ->willReturn($userId);
        $toWallet = $this->createMock(Wallet::class);
        $toWallet
            ->method('getCurrency')
            ->willReturn(Currency::EUR);
        $toWallet->method('getBalance')->willReturn(100.0);
        $toWallet
            ->method('getUserId')
            ->willReturn($userId);

        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->exchangeRateService
            ->expects(self::exactly(2))
            ->method('getExchangeRateBetween')
            ->with(Currency::PLN, Currency::EUR)
            ->willReturn(0.25);

        $this->spreadService
            ->expects(self::once())
            ->method('calculateSpread')
            ->with(250.0, Currency::PLN, Currency::EUR)
            ->willReturn('1.00');

        $this->walletRepository->expects(self::once())
            ->method('reserveBalance')->with(1, '1000.00')->willReturn(true);
        $this->walletRepository->expects(self::never())->method('addToBalance');

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class));

        $transaction = $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertSame(5000.0, $fromWallet->getBalance());
        self::assertSame(100.0, $toWallet->getBalance());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame('1000.00', $transaction->getFromAmount());
        self::assertSame('249.0000', $transaction->getToAmount());
        self::assertSame('0.250000', $transaction->getExchangeRate());
        self::assertSame('1.00', $transaction->getSpread());
        self::assertSame(Currency::PLN, $transaction->getFromCurrency());
        self::assertSame(Currency::EUR, $transaction->getToCurrency());
    }

    public function testTransferRejectsSameWallet(): void
    {
        $this->walletRepository->expects(self::never())->method('findById');
        $this->expectException(InvalidArgumentException::class);
        $this->transferService->transfer(1, 1, 1, '1.00');
    }

    public function testTransferRejectsInsufficientBalance(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);
        $wallet->setBalance(10.0);
        $this->walletRepository->method('findById')->willReturnMap([[1, $wallet], [2, Wallet::create(1, Currency::EUR)]]);
        $this->walletRepository->expects(self::never())->method('reserveBalance');
        $this->transactionRepository->expects(self::never())->method('save');
        $this->expectException(InvalidArgumentException::class);
        $this->transferService->transfer(1, 1, 2, '11.00');
    }

    public function testExactlyFifteenThousandEuroDoesNotRequireFraudReview(): void
    {
        self::assertFalse($this->transferAtEuroAmount('15000.00')->requiresAntiFraudCheck());
    }

    public function testAmountAboveFifteenThousandEuroRequiresFraudReview(): void
    {
        self::assertTrue($this->transferAtEuroAmount('15000.01')->requiresAntiFraudCheck());
    }

    private function transferAtEuroAmount(string $amount): Transaction
    {
        $from = Wallet::create(1, Currency::EUR);
        $from->setBalance(20000.0);
        $to = Wallet::create(1, Currency::USD);
        $this->walletRepository->method('findById')->willReturnMap([[1, $from], [2, $to]]);
        $this->walletRepository->method('reserveBalance')->willReturn(true);
        $this->exchangeRateService->method('getExchangeRateBetween')->willReturn(1.0);
        $this->spreadService->method('calculateSpread')->willReturn('0.00');
        $this->transactionRepository->method('save');

        return $this->transferService->transfer(1, 1, 2, $amount);
    }

    public function testTransferThrowsWhenFromWalletNotFound(): void
    {
        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(99)
            ->willReturn(null);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 99, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletNotFound(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [99, null],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 1, 99, '100.00');
    }

    public function testTransferThrowsWhenFromWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(2, Currency::PLN);

        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($fromWallet);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $toWallet = Wallet::create(2, Currency::EUR);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 2 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }
}
