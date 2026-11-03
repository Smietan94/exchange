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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $transaction = $this->transferUsingActualRates(Currency::EUR, Currency::USD, '15000.0000');

        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
    }

    public function testAmountAboveFifteenThousandEuroRequiresFraudReview(): void
    {
        $transaction = $this->transferUsingActualRates(Currency::PLN, Currency::EUR, '63583.5001');

        self::assertTrue($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
        self::assertLessThan(15_000.0, (float) $transaction->getToAmount());
    }

    public function testAmountBelowFifteenThousandEuroDoesNotRequireFraudReview(): void
    {
        $transaction = $this->transferUsingActualRates(Currency::PLN, Currency::EUR, '63583.4999');

        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
    }

    public function testFraudThresholdConvertsUsdSourceToEurRegardlessOfTargetCurrency(): void
    {
        $transaction = $this->transferUsingActualRates(Currency::USD, Currency::GBP, '17440.00');

        self::assertTrue($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
        self::assertLessThan(15_000.0, (float) $transaction->getToAmount());
    }

    #[DataProvider('invalidAmountsProvider')]
    public function testTransferRejectsInvalidOrUnsupportedAmounts(string $amount): void
    {
        $this->walletRepository->expects(self::never())->method('findById');
        $this->walletRepository->expects(self::never())->method('reserveBalance');
        $this->transactionRepository->expects(self::never())->method('save');
        $this->expectException(InvalidArgumentException::class);
        $this->transferService->transfer(1, 1, 2, $amount);
    }

    public static function invalidAmountsProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1.00'],
            'non numeric' => ['not-a-number'],
            'NaN text' => ['NAN'],
            'infinite text' => ['INF'],
            'scientific notation' => ['1e3'],
            'too many decimal places' => ['1.00000'],
            'beyond DECIMAL(15,4) range' => ['100000000000.0000'],
        ];
    }

    public function testAmountNormalizerRejectsNonFiniteAndNonScalarValues(): void
    {
        foreach ([INF, -INF, NAN, [], true, null] as $amount) {
            self::assertNull(TransferService::normalizeAmount($amount));
        }
    }

    private function transferUsingActualRates(Currency $fromCurrency, Currency $toCurrency, string $amount): Transaction
    {
        $from = Wallet::create(1, $fromCurrency);
        $from->setBalance(200_000_000.0);
        $to = Wallet::create(1, $toCurrency);
        $this->walletRepository->method('findById')->willReturnMap([[1, $from], [2, $to]]);
        $this->walletRepository->method('reserveBalance')->willReturn(true);
        $service = new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            new ExchangeRateService(),
            new SpreadService(),
        );

        return $service->transfer(1, 1, 2, $amount);
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
