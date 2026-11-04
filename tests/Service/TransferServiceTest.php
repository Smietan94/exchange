<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\WalletNotFoundException;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransactionProcessorService;
use App\Service\TransferService;
use Brick\Money\Money;
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
    private CompanyWalletRepositoryInterface $companyWalletRepository;
    private TransferService $transferService;
    private array $databaseState = [];

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->exchangeRateService = $this->createMock(ExchangeRateService::class);
        $this->spreadService = $this->createMock(SpreadService::class);
        $this->companyWalletRepository = $this->createMock(CompanyWalletRepositoryInterface::class);

        $this->transferService = new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->exchangeRateService,
            $this->spreadService,
            new TransactionProcessorService(
                $this->walletRepository,
                $this->transactionRepository,
                $this->companyWalletRepository,
            ),
        );
        $this->transactionRepository->method('transactional')->willReturnCallback(
            function (callable $operation): mixed {
                $snapshot = $this->databaseState;
                try {
                    return $operation();
                } catch (\Throwable $exception) {
                    $this->databaseState = $snapshot;
                    throw $exception;
                }
            },
        );
        $this->transactionRepository->method('transition')->willReturn(true);
    }

    public function testTransferSuccessfully(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet
            ->method('getCurrency')
            ->willReturn(Currency::PLN);
        $fromWallet->method('getBalance')->willReturn(Money::of('5000.00', 'PLN'));
        $fromWallet
            ->method('getUserId')
            ->willReturn($userId);
        $toWallet = $this->createMock(Wallet::class);
        $toWallet
            ->method('getCurrency')
            ->willReturn(Currency::EUR);
        $toWallet->method('getBalance')->willReturn(Money::of('100.00', 'EUR'));
        $toWallet
            ->method('getUserId')
            ->willReturn($userId);

        $this->walletRepository
            ->expects(self::exactly(4))
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->exchangeRateService
            ->expects(self::once())
            ->method('getExchangeRateBetween')
            ->with(Currency::PLN, Currency::EUR)
            ->willReturn('0.250000');
        $this->exchangeRateService->method('exchange')->willReturn(Money::of('250.00', 'EUR'));
        $this->exchangeRateService->method('exceedsEurThreshold')->willReturn(false);

        $this->spreadService
            ->expects(self::once())
            ->method('calculateSpread')
            ->with(Money::of('250.00', 'EUR'), Currency::PLN, Currency::EUR)
            ->willReturn(Money::of('1.00', 'EUR'));

        $this->walletRepository->expects(self::once())
            ->method('reserveBalance')->with(1, Money::of('1000.00', 'PLN'))->willReturn(true);
        $this->walletRepository->expects(self::once())->method('addToBalance')->with(2, Money::of('249.00', 'EUR'));
        $this->companyWalletRepository->expects(self::once())->method('addToBalance')->with(Currency::EUR, Money::of('1.00', 'EUR'));

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->callback(static fn (Transaction $transaction): bool => TransactionStatus::PENDING === $transaction->getStatus()));
        $this->transactionRepository->expects(self::once())
            ->method('transition')
            ->with($this->isInstanceOf(Transaction::class), TransactionStatus::PENDING, TransactionStatus::COMPLETED, null)
            ->willReturn(true);

        $transaction = $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertTrue(Money::of('1000.00', 'PLN')->isEqualTo($transaction->getFromAmount()));
        self::assertTrue(Money::of('249.00', 'EUR')->isEqualTo($transaction->getToAmount()));
        self::assertSame('0.250000', $transaction->getExchangeRate());
        self::assertTrue(Money::of('1.00', 'EUR')->isEqualTo($transaction->getSpread()));
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
        $wallet->setBalance(Money::of('10.00', 'PLN'));
        $this->walletRepository->method('findById')->willReturnMap([[1, $wallet], [2, Wallet::create(1, Currency::EUR)]]);
        $this->walletRepository->expects(self::never())->method('reserveBalance');
        $this->transactionRepository->expects(self::never())->method('save');
        $this->expectException(InvalidArgumentException::class);
        $this->transferService->transfer(1, 1, 2, '11.00');
    }

    public function testOrdinaryTransferRollsBackReservationAndRecordWhenImmediateProcessingFails(): void
    {
        $from = Wallet::create(1, Currency::PLN);
        $from->setBalance(Money::of('500.00', 'PLN'));
        $to = Wallet::create(1, Currency::EUR);
        $this->walletRepository->method('findById')->willReturnMap([[1, $from], [2, $to]]);
        $this->exchangeRateService->method('getExchangeRateBetween')->willReturn('0.250000');
        $this->exchangeRateService->method('exchange')->willReturn(Money::of('25.00', 'EUR'));
        $this->exchangeRateService->method('exceedsEurThreshold')->willReturn(false);
        $this->spreadService->method('calculateSpread')->willReturn(Money::of('1.00', 'EUR'));

        $this->databaseState = [
            'sourceBalance' => '500.00',
            'destinationBalance' => '100.00',
            'companyBalance' => '2.00',
            'transactionSaved' => false,
            'status' => TransactionStatus::PENDING,
        ];
        $this->walletRepository->expects(self::once())->method('reserveBalance')->willReturnCallback(
            function (int $walletId, Money $amount): bool {
                $this->databaseState['sourceBalance'] = Money::of($this->databaseState['sourceBalance'], 'PLN')->minus($amount)->getAmount()->toString();

                return true;
            },
        );
        $this->transactionRepository->expects(self::once())->method('save')->willReturnCallback(
            function (): void {
                $this->databaseState['transactionSaved'] = true;
            },
        );
        $this->transactionRepository->method('transition')->willReturnCallback(
            function (Transaction $transaction, TransactionStatus $expected, TransactionStatus $next): bool {
                if ($this->databaseState['status'] !== $expected) {
                    return false;
                }
                $this->databaseState['status'] = $next;

                return true;
            },
        );
        $this->walletRepository->method('addToBalance')->willReturnCallback(
            function (int $walletId, Money $amount): void {
                $this->databaseState['destinationBalance'] = Money::of($this->databaseState['destinationBalance'], 'EUR')->plus($amount)->getAmount()->toString();
            },
        );
        $this->companyWalletRepository->expects(self::once())->method('addToBalance')
            ->willThrowException(new \RuntimeException('Company wallet write failed.'));

        try {
            $this->transferService->transfer(1, 1, 2, '100.00');
            self::fail('Expected immediate processing to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Company wallet write failed.', $exception->getMessage());
        }

        self::assertSame([
            'sourceBalance' => '500.00',
            'destinationBalance' => '100.00',
            'companyBalance' => '2.00',
            'transactionSaved' => false,
            'status' => TransactionStatus::PENDING,
        ], $this->databaseState);
    }

    public function testExactlyFifteenThousandEuroDoesNotRequireFraudReview(): void
    {
        $transaction = $this->transferUsingActualRates(Currency::EUR, Currency::USD, '15000.00');

        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testAmountAboveFifteenThousandEuroRequiresFraudReview(): void
    {
        $transaction = $this->transferUsingActualRates(Currency::PLN, Currency::EUR, '63583.51');

        self::assertTrue($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
        self::assertTrue($transaction->getToAmount()->isLessThan(Money::of('15000.00', 'EUR')));
    }

    public function testAmountBelowFifteenThousandEuroDoesNotRequireFraudReview(): void
    {
        $transaction = $this->transferUsingActualRates(Currency::PLN, Currency::EUR, '63583.49');

        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testFraudThresholdConvertsUsdSourceToEurRegardlessOfTargetCurrency(): void
    {
        $transaction = $this->transferUsingActualRates(Currency::USD, Currency::GBP, '17440.00');

        self::assertTrue($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
        self::assertTrue($transaction->getToAmount()->isLessThan(Money::of('15000.00', 'GBP')));
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
        $from->setBalance(Money::of('200000000', $fromCurrency->value));
        $to = Wallet::create(1, $toCurrency);
        $this->walletRepository->method('findById')->willReturnMap([[1, $from], [2, $to]]);
        $this->walletRepository->method('reserveBalance')->willReturn(true);
        $service = new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            new ExchangeRateService(),
            new SpreadService(),
            new TransactionProcessorService(
                $this->walletRepository,
                $this->transactionRepository,
                $this->companyWalletRepository,
            ),
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
