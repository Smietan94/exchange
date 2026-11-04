<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\WalletDeletionConflictException;
use App\Exception\WalletNotFoundException;
use App\Service\MoneyAmount;
use Brick\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use ReflectionClass;

readonly class WalletRepository implements WalletRepositoryInterface
{
    private const string TABLE_NAME = 'wallets';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws Exception
     */
    public function findById(int $id): ?Wallet
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('id = :id')
            ->andWhere('deleted_at IS NULL');

        $row = $this->connection->fetchAssociative($qb->getSQL(), ['id' => $id]);

        if (!$row) {
            return null;
        }

        return $this->buildEntity($row);
    }

    /**
     * @return Wallet[]
     *
     * @throws Exception
     */
    public function findByUserId(int $userId): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('user_id = :user_id')
            ->andWhere('deleted_at IS NULL');

        $rows = $this->connection->fetchAllAssociative($qb->getSQL(), ['user_id' => $userId]);

        return array_map($this->buildEntity(...), $rows);
    }

    /**
     * @throws Exception
     */
    public function findByUserIdAndCurrency(int $userId, Currency $currency): ?Wallet
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('user_id = :user_id')
            ->andWhere('currency = :currency')
            ->andWhere('deleted_at IS NULL');

        $row = $this->connection->fetchAssociative($qb->getSQL(), [
            'user_id' => $userId,
            'currency' => $currency->value,
        ]);

        if (!$row) {
            return null;
        }

        return $this->buildEntity($row);
    }

    public function lockActiveWallets(int $firstWalletId, int $secondWalletId): bool
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM wallets WHERE id IN (:first_id, :second_id) AND deleted_at IS NULL ORDER BY id FOR UPDATE',
            ['first_id' => $firstWalletId, 'second_id' => $secondWalletId],
        );

        return 2 === count($ids);
    }

    public function archiveOwnedWallet(int $walletId, int $userId): void
    {
        $this->connection->transactional(function () use ($walletId, $userId): void {
            $row = $this->connection->fetchAssociative(
                'SELECT id, currency, balance FROM wallets WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL FOR UPDATE',
                ['id' => $walletId, 'user_id' => $userId],
            );
            if (!$row) {
                throw new WalletNotFoundException($walletId);
            }

            $currency = Currency::from($row['currency']);
            $balance = MoneyAmount::fromDatabase((string) $row['balance'], $currency);
            $pendingCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM transactions WHERE (from_wallet_id = :from_wallet_id OR to_wallet_id = :to_wallet_id) AND status IN (:pending, :fraud_review)',
                [
                    'from_wallet_id' => $walletId,
                    'to_wallet_id' => $walletId,
                    'pending' => TransactionStatus::PENDING->value,
                    'fraud_review' => TransactionStatus::FRAUD_REVIEW->value,
                ],
            );

            if (!$balance->isZero() || $pendingCount > 0) {
                throw new WalletDeletionConflictException();
            }

            $now = (new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $affected = $this->connection->executeStatement(
                'UPDATE wallets SET deleted_at = :deleted_at WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL AND balance = 0',
                ['deleted_at' => $now, 'id' => $walletId, 'user_id' => $userId],
            );
            if (1 !== $affected) {
                throw new WalletDeletionConflictException();
            }
        });
    }

    /**
     * @throws Exception
     */
    public function save(Wallet $wallet): void
    {
        if (null === $wallet->getId()) {
            $this->insert($wallet);
        } else {
            $this->update($wallet);
        }
    }

    public function reserveBalance(int $walletId, Money $amount): bool
    {
        $now = (new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return 1 === $this->connection->executeStatement(
            'UPDATE wallets SET balance = balance - :amount, last_activity_at = :now WHERE id = :id AND currency = :currency AND balance >= :amount AND deleted_at IS NULL',
            ['amount' => MoneyAmount::format($amount), 'now' => $now, 'id' => $walletId, 'currency' => $amount->getCurrency()->getCurrencyCode()],
        );
    }

    public function depositBalance(int $walletId, Money $amount): bool
    {
        $now = (new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return 1 === $this->connection->executeStatement(
            'UPDATE wallets SET balance = balance + :amount, last_activity_at = :now WHERE id = :id AND currency = :currency AND is_blocked = 0 AND deleted_at IS NULL',
            ['amount' => MoneyAmount::format($amount), 'now' => $now, 'id' => $walletId, 'currency' => $amount->getCurrency()->getCurrencyCode()],
        );
    }

    public function addToBalance(int $walletId, Money $amount): void
    {
        $now = (new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $affected = $this->connection->executeStatement(
            'UPDATE wallets SET balance = balance + :amount, last_activity_at = :now WHERE id = :id AND currency = :currency AND deleted_at IS NULL',
            ['amount' => MoneyAmount::format($amount), 'now' => $now, 'id' => $walletId, 'currency' => $amount->getCurrency()->getCurrencyCode()],
        );

        if (1 !== $affected) {
            throw new \RuntimeException('Wallet balance could not be updated because the wallet is inactive or the currency does not match.');
        }
    }

    private function buildEntity(array $row): Wallet
    {
        return new Wallet(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            currency: Currency::from($row['currency']),
            balance: MoneyAmount::fromDatabase((string) $row['balance'], Currency::from($row['currency'])),
            isBlocked: (bool) $row['is_blocked'],
            lastActivityAt: null !== $row['last_activity_at'] ? new DateTimeImmutable($row['last_activity_at']) : null,
            createdAt: new DateTimeImmutable($row['created_at']),
        );
    }

    /**
     * @throws Exception
     */
    private function insert(Wallet $wallet): void
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->insert(self::TABLE_NAME)
            ->values([
                'user_id' => ':user_id',
                'currency' => ':currency',
                'balance' => ':balance',
                'is_blocked' => ':is_blocked',
                'last_activity_at' => ':last_activity_at',
                'created_at' => ':created_at',
            ]);

        $this->connection->executeQuery(
            $qb->getSQL(),
            [
                'user_id' => $wallet->getUserId(),
                'currency' => $wallet->getCurrency()->value,
                'balance' => MoneyAmount::format($wallet->getBalance()),
                'is_blocked' => (int) $wallet->isBlocked(),
                'last_activity_at' => $wallet->getLastActivityAt()?->setTimezone(timezone: new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'created_at' => $wallet->getCreatedAt()
                    ->setTimezone(timezone: new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s'),
            ]
        );

        $id = (int) $this->connection->lastInsertId();

        $reflectionClass = new ReflectionClass($wallet);
        $reflectionProperty = $reflectionClass->getProperty('id');
        $reflectionProperty->setValue(objectOrValue: $wallet, value: $id);
    }

    /**
     * @throws Exception
     */
    private function update(Wallet $wallet): void
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->update(self::TABLE_NAME)
            ->set('balance', ':balance')
            ->set('is_blocked', ':is_blocked')
            ->set('last_activity_at', ':last_activity_at')
            ->where('id = :id');

        $this->connection->executeQuery(
            $qb->getSQL(),
            [
                'balance' => MoneyAmount::format($wallet->getBalance()),
                'is_blocked' => (int) $wallet->isBlocked(),
                'last_activity_at' => $wallet->getLastActivityAt()?->setTimezone(timezone: new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'id' => $wallet->getId(),
            ]
        );
    }
}
