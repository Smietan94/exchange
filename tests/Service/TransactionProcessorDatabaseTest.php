<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Entity\Transaction;
use App\Repository\CompanyWalletRepository;
use App\Repository\TransactionRepository;
use App\Repository\WalletRepository;
use App\Service\MoneyAmount;
use App\Service\TransactionProcessorService;
use Brick\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * These tests use temporary MariaDB tables so they exercise real DBAL transactions
 * without requiring migrations or modifying the persistent test schema.
 */
final class TransactionProcessorDatabaseTest extends KernelTestCase
{
    private Connection $connection;
    private TransactionRepository $transactions;
    private TransactionProcessorService $processor;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (Throwable $exception) {
            self::markTestSkipped('MariaDB is unavailable for DBAL integration tests: '.$exception->getMessage());
        }

        $this->createTemporaryTables();
        $this->applyWalletArchiveMigration();
        $this->applyActiveWalletUniqueMigration();
        $wallets = new WalletRepository($this->connection);
        $this->transactions = new TransactionRepository($this->connection);
        $this->processor = new TransactionProcessorService(
            $wallets,
            $this->transactions,
            new CompanyWalletRepository($this->connection),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isConnected()) {
            $this->connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS company_wallets');
            $this->connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS transactions');
            $this->connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS wallets');
        }

        parent::tearDown();
    }

    public function testStaleReloadedCandidatesCannotApproveOrRejectAnAlreadyCompletedTransaction(): void
    {
        $this->seedFraudTransaction();
        $firstCandidate = $this->transactions->findById(1);
        $staleCandidate = $this->transactions->findById(1);
        self::assertNotNull($firstCandidate);
        self::assertNotNull($staleCandidate);

        $this->processor->approveFraudReview($firstCandidate);
        $this->processor->approveFraudReview($staleCandidate);
        $this->processor->rejectFraudReview($staleCandidate);

        self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM transactions WHERE id = 1'));
        self::assertSame('25.0000', $this->connection->fetchOne('SELECT balance FROM wallets WHERE id = 2'));
        self::assertSame('0.5000', $this->connection->fetchOne('SELECT balance FROM company_wallets WHERE currency = :currency', ['currency' => 'EUR']));
    }

    public function testRejectedReviewReturnsReservationOnlyOnce(): void
    {
        $this->seedFraudTransaction();
        $firstCandidate = $this->transactions->findById(1);
        $staleCandidate = $this->transactions->findById(1);
        self::assertNotNull($firstCandidate);
        self::assertNotNull($staleCandidate);

        $this->processor->rejectFraudReview($firstCandidate);
        $this->processor->rejectFraudReview($staleCandidate);

        self::assertSame('rejected', $this->connection->fetchOne('SELECT status FROM transactions WHERE id = 1'));
        self::assertSame('150.0000', $this->connection->fetchOne('SELECT balance FROM wallets WHERE id = 1'));
        self::assertSame('0.0000', $this->connection->fetchOne('SELECT balance FROM wallets WHERE id = 2'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM company_wallets'));
    }

    public function testFailureAfterStatusAndDestinationWritesRollsBackWholeApproval(): void
    {
        $this->seedFraudTransaction();
        $this->connection->executeStatement('DROP TEMPORARY TABLE company_wallets');
        $this->connection->executeStatement(<<<'SQL'
            CREATE TEMPORARY TABLE company_wallets (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                currency VARCHAR(3) NOT NULL UNIQUE,
                balance DECIMAL(20,4) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT reject_eur_spread CHECK (currency <> 'EUR')
            ) ENGINE=InnoDB
            SQL);

        $candidate = $this->transactions->findById(1);
        self::assertNotNull($candidate);

        try {
            $this->processor->approveFraudReview($candidate);
            self::fail('Expected the company wallet write to fail.');
        } catch (Throwable) {
            self::assertSame('fraud_review', $this->connection->fetchOne('SELECT status FROM transactions WHERE id = 1'));
            self::assertSame('0.0000', $this->connection->fetchOne('SELECT balance FROM wallets WHERE id = 2'));
            self::assertSame(TransactionStatus::FRAUD_REVIEW, $candidate->getStatus());
        }
    }

    public function testTwentyPlaceExchangeRateRoundTripsThroughMariaDbDecimalColumn(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261104100000.php';
        $migration = new \DoctrineMigrations\Version20261104100000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $rate = '0.23591025973719597065';
        $transaction = Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: \Brick\Money\Money::of('100000.00', 'PLN'),
            toAmount: \Brick\Money\Money::of('23433.76', 'EUR'),
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: \Brick\Money\Money::of('157.27', 'EUR'),
            exchangeRate: $rate,
            requiresAntiFraudCheck: true,
        );

        $this->transactions->save($transaction);
        self::assertNotNull($transaction->getId());
        self::assertSame($rate, $this->connection->fetchOne('SELECT exchange_rate FROM transactions WHERE id = :id', ['id' => $transaction->getId()]));
        $reloaded = $this->transactions->findById($transaction->getId());
        self::assertNotNull($reloaded);
        self::assertSame($rate, $reloaded->getExchangeRate());
        self::assertSame('23433.76', MoneyAmount::format($reloaded->getToAmount()));
        self::assertSame('157.27', MoneyAmount::format($reloaded->getSpread()));
    }

    public function testAtomicDepositsAccumulateAndRespectWalletBlockOnMariaDb(): void
    {
        $this->connection->insert('wallets', [
            'id' => 1,
            'user_id' => 1,
            'currency' => Currency::EUR->value,
            'balance' => '100.0000',
            'is_blocked' => 0,
            'last_activity_at' => null,
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $wallets = new WalletRepository($this->connection);

        self::assertTrue($wallets->depositBalance(1, Money::of('50.00', 'EUR')));
        self::assertTrue($wallets->depositBalance(1, Money::of('25.00', 'EUR')));
        self::assertSame('175.0000', $this->connection->fetchOne('SELECT balance FROM wallets WHERE id = 1'));
        self::assertNotFalse($this->connection->fetchOne('SELECT last_activity_at FROM wallets WHERE id = 1'));

        $this->connection->executeStatement('UPDATE wallets SET is_blocked = 1 WHERE id = 1');
        self::assertFalse($wallets->depositBalance(1, Money::of('10.00', 'EUR')));
        self::assertSame('175.0000', $this->connection->fetchOne('SELECT balance FROM wallets WHERE id = 1'));
    }

    public function testOwnedEmptyWalletCanBeArchivedAndItsHistoryRemainsQueryable(): void
    {
        $this->insertWallet(1, 1, Currency::EUR, '0.0000');
        $this->insertWallet(2, 1, Currency::PLN, '1.0000');
        $this->insertTransaction(1, 1, 2, TransactionStatus::COMPLETED);
        $wallets = new WalletRepository($this->connection);

        $wallets->archiveOwnedWallet(1, 1);

        self::assertNull($wallets->findById(1));
        self::assertCount(1, $wallets->findByUserId(1));
        self::assertNotNull($this->connection->fetchOne('SELECT deleted_at FROM wallets WHERE id = 1'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM transactions WHERE id = 1'));
        self::assertCount(1, $this->transactions->findByWalletId(1));

        self::assertFalse($wallets->depositBalance(1, Money::of('1.00', 'EUR')));
        self::assertFalse($wallets->reserveBalance(1, Money::of('1.00', 'EUR')));
        self::assertFalse($wallets->lockActiveWallets(1, 2));
        try {
            $wallets->addToBalance(1, Money::of('1.00', 'EUR'));
            self::fail('Expected posting to an archived wallet to fail.');
        } catch (\RuntimeException) {
            self::assertSame('0.0000', $this->connection->fetchOne('SELECT balance FROM wallets WHERE id = 1'));
        }
    }

    public function testArchivedWalletAllowsNewWalletOfSameCurrencyButDatabaseStillEnforcesOneActiveWallet(): void
    {
        $this->insertWallet(1, 1, Currency::EUR, '0.0000');
        $wallets = new WalletRepository($this->connection);
        $wallets->archiveOwnedWallet(1, 1);

        self::assertNull($wallets->findByUserIdAndCurrency(1, Currency::EUR));
        $service = new \App\Service\WalletService($wallets);
        $newWallet = $service->createWallet(1, Currency::EUR);

        self::assertNotNull($newWallet->getId());
        self::assertNotSame(1, $newWallet->getId());
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM wallets WHERE user_id = 1 AND currency = :currency AND deleted_at IS NULL',
            ['currency' => Currency::EUR->value],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM wallets WHERE id = 1 AND deleted_at IS NOT NULL'));

        try {
            $this->insertWallet(3, 1, Currency::EUR, '0.0000');
            self::fail('Expected the database unique index to reject a second active wallet.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM wallets WHERE user_id = 1 AND currency = :currency AND deleted_at IS NULL',
                ['currency' => Currency::EUR->value],
            ));
        }
    }

    public function testWalletWithBalanceCannotBeArchived(): void
    {
        $this->insertWallet(1, 1, Currency::EUR, '0.0100');
        $wallets = new WalletRepository($this->connection);

        try {
            $wallets->archiveOwnedWallet(1, 1);
            self::fail('Expected a nonzero wallet balance to prevent archival.');
        } catch (\App\Exception\WalletDeletionConflictException) {
            self::assertNull($this->connection->fetchOne('SELECT deleted_at FROM wallets WHERE id = 1'));
        }
    }

    public function testOtherUsersWalletCannotBeArchived(): void
    {
        $this->insertWallet(1, 2, Currency::EUR, '0.0000');
        $wallets = new WalletRepository($this->connection);

        try {
            $wallets->archiveOwnedWallet(1, 1);
            self::fail('Expected an ownership mismatch to look like a missing wallet.');
        } catch (\App\Exception\WalletNotFoundException) {
            self::assertNull($this->connection->fetchOne('SELECT deleted_at FROM wallets WHERE id = 1'));
        }
    }

    public function testPendingAndFraudReviewTransactionsPreventArchival(): void
    {
        $this->insertWallet(1, 1, Currency::EUR, '0.0000');
        $this->insertWallet(2, 1, Currency::PLN, '0.0000');
        $wallets = new WalletRepository($this->connection);

        foreach ([TransactionStatus::PENDING, TransactionStatus::FRAUD_REVIEW] as $index => $status) {
            $transactionId = $index + 1;
            $this->insertTransaction($transactionId, 1, 2, $status);

            try {
                $wallets->archiveOwnedWallet(1, 1);
                self::fail(sprintf('Expected %s to prevent archival.', $status->value));
            } catch (\App\Exception\WalletDeletionConflictException) {
                self::assertNull($this->connection->fetchOne('SELECT deleted_at FROM wallets WHERE id = 1'));
            }
        }
    }

    private function applyWalletArchiveMigration(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261104110000.php';
        $migration = new \DoctrineMigrations\Version20261104110000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function applyActiveWalletUniqueMigration(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261104120000.php';
        $migration = new \DoctrineMigrations\Version20261104120000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function insertWallet(int $id, int $userId, Currency $currency, string $balance): void
    {
        $this->connection->insert('wallets', [
            'id' => $id,
            'user_id' => $userId,
            'currency' => $currency->value,
            'balance' => $balance,
            'is_blocked' => 0,
            'last_activity_at' => null,
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function insertTransaction(int $id, int $fromWalletId, int $toWalletId, TransactionStatus $status): void
    {
        $this->connection->insert('transactions', [
            'id' => $id,
            'from_wallet_id' => $fromWalletId,
            'to_wallet_id' => $toWalletId,
            'from_amount' => '1.0000',
            'to_amount' => '1.0000',
            'from_currency' => Currency::EUR->value,
            'to_currency' => Currency::PLN->value,
            'spread' => '0.0100',
            'exchange_rate' => '1.000000',
            'status' => $status->value,
            'requires_anti_fraud_check' => (int) (TransactionStatus::FRAUD_REVIEW === $status),
            'anti_fraud_checked_at' => null,
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function createTemporaryTables(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TEMPORARY TABLE wallets (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                currency VARCHAR(3) NOT NULL,
                balance DECIMAL(20,4) NOT NULL,
                is_blocked TINYINT(1) NOT NULL DEFAULT 0,
                last_activity_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY wallet_user_currency_unique (user_id, currency)
            ) ENGINE=InnoDB
            SQL);
        $this->connection->executeStatement(<<<'SQL'
            CREATE TEMPORARY TABLE transactions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                from_wallet_id INT NOT NULL,
                to_wallet_id INT NOT NULL,
                from_amount DECIMAL(20,4) NOT NULL,
                to_amount DECIMAL(20,4) NOT NULL,
                from_currency VARCHAR(3) NOT NULL,
                to_currency VARCHAR(3) NOT NULL,
                spread DECIMAL(20,4) NOT NULL,
                exchange_rate DECIMAL(15,6) NOT NULL,
                status VARCHAR(20) NOT NULL,
                requires_anti_fraud_check TINYINT(1) NOT NULL,
                anti_fraud_checked_at DATETIME NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB
            SQL);
        $this->connection->executeStatement(<<<'SQL'
            CREATE TEMPORARY TABLE company_wallets (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                currency VARCHAR(3) NOT NULL UNIQUE,
                balance DECIMAL(20,4) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB
            SQL);
    }

    private function seedFraudTransaction(): void
    {
        $this->connection->insert('wallets', [
            'id' => 1,
            'user_id' => 1,
            'currency' => Currency::PLN->value,
            'balance' => '50.0000',
            'is_blocked' => 0,
            'last_activity_at' => null,
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->connection->insert('wallets', [
            'id' => 2,
            'user_id' => 1,
            'currency' => Currency::EUR->value,
            'balance' => '0.0000',
            'is_blocked' => 0,
            'last_activity_at' => null,
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->connection->insert('transactions', [
            'id' => 1,
            'from_wallet_id' => 1,
            'to_wallet_id' => 2,
            'from_amount' => '100.0000',
            'to_amount' => '25.0000',
            'from_currency' => Currency::PLN->value,
            'to_currency' => Currency::EUR->value,
            'spread' => '0.5000',
            'exchange_rate' => '0.250000',
            'status' => TransactionStatus::FRAUD_REVIEW->value,
            'requires_anti_fraud_check' => 1,
            'anti_fraud_checked_at' => null,
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }
}
