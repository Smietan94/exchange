<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CompanyWallet;
use App\Enum\Currency;
use App\Service\MoneyAmount;
use Brick\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

readonly class CompanyWalletRepository implements CompanyWalletRepositoryInterface
{
    private const string TABLE_NAME = 'company_wallets';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws Exception
     */
    public function findByCurrency(Currency $currency): ?CompanyWallet
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('currency = :currency');

        $row = $this->connection->fetchAssociative($qb->getSQL(), ['currency' => $currency->value]);

        if (!$row) {
            return null;
        }

        return $this->buildEntity($row);
    }

    /**
     * @return CompanyWallet[]
     *
     * @throws Exception
     */
    public function findAll(): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('currency', 'ASC');

        $rows = $this->connection->fetchAllAssociative($qb->getSQL(), []);

        return array_map($this->buildEntity(...), $rows);
    }

    /**
     * @throws Exception
     */
    public function addToBalance(Currency $currency, Money $amount): void
    {
        if (!$amount->getCurrency()->isEqualTo($currency->value)) {
            throw new \InvalidArgumentException('Company wallet amount currency must match wallet currency.');
        }

        $now = new DateTimeImmutable()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'INSERT INTO company_wallets (currency, balance, created_at, updated_at) VALUES (:currency, :amount, :created_at, :updated_at) ON DUPLICATE KEY UPDATE balance = company_wallets.balance + :increment, updated_at = :updated_at_existing',
            [
                'currency' => $currency->value,
                'amount' => MoneyAmount::format($amount),
                'increment' => MoneyAmount::format($amount),
                'created_at' => $now,
                'updated_at' => $now,
                'updated_at_existing' => $now,
            ],
        );
    }

    private function buildEntity(array $row): CompanyWallet
    {
        $currency = Currency::from($row['currency']);

        return new CompanyWallet(
            id: (int) $row['id'],
            currency: $currency,
            balance: MoneyAmount::fromDatabase((string) $row['balance'], $currency),
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: new DateTimeImmutable($row['updated_at']),
        );
    }
}
