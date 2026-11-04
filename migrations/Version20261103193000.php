<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store monetary amounts in exact decimal columns and normalize legacy values to currency precision';
    }

    public function up(Schema $schema): void
    {
        // The old flow booked every transfer into wallet balances when it was requested.
        // Refuse to reinterpret those in-flight rows under the new reservation semantics.
        $inFlightCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM transactions WHERE status IN ('pending', 'fraud_review')");
        if ($inFlightCount > 0) {
            throw new \RuntimeException(sprintf(
                'Cannot normalize monetary precision while %d pending or fraud-review transactions remain. Reconcile these legacy transfers before retrying the migration.',
                $inFlightCount,
            ));
        }

        // Keep wallet totals aligned with transaction history as each ledger entry is
        // normalized to the currency precision. Previously balances and entries were
        // rounded independently, which could make the ledger fail to reconcile.
        $this->addSql(<<<'SQL'
            UPDATE wallets w
            SET w.balance = w.balance
                + COALESCE((SELECT SUM(t.from_amount - ROUND(t.from_amount, CASE WHEN t.from_currency = 'JPY' THEN 0 ELSE 2 END))
                    FROM transactions t WHERE t.from_wallet_id = w.id), 0)
                + COALESCE((SELECT SUM(ROUND(t.to_amount, CASE WHEN t.to_currency = 'JPY' THEN 0 ELSE 2 END) - t.to_amount)
                    FROM transactions t WHERE t.to_wallet_id = w.id), 0)
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE company_wallets cw
            SET cw.balance = cw.balance
                + COALESCE((SELECT SUM(ROUND(t.spread, CASE WHEN t.to_currency = 'JPY' THEN 0 ELSE 2 END) - t.spread)
                    FROM transactions t WHERE t.to_currency = cw.currency), 0)
            SQL);

        $this->addSql("UPDATE wallets SET balance = ROUND(balance, CASE WHEN currency = 'JPY' THEN 0 ELSE 2 END)");
        $this->addSql("UPDATE company_wallets SET balance = ROUND(balance, CASE WHEN currency = 'JPY' THEN 0 ELSE 2 END)");
        $this->addSql("UPDATE transactions SET from_amount = ROUND(from_amount, CASE WHEN from_currency = 'JPY' THEN 0 ELSE 2 END), to_amount = ROUND(to_amount, CASE WHEN to_currency = 'JPY' THEN 0 ELSE 2 END), spread = ROUND(spread, CASE WHEN to_currency = 'JPY' THEN 0 ELSE 2 END)");

        $this->addSql('ALTER TABLE wallets MODIFY balance DECIMAL(20,4) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE company_wallets MODIFY balance DECIMAL(20,4) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE transactions MODIFY from_amount DECIMAL(20,4) NOT NULL, MODIFY to_amount DECIMAL(20,4) NOT NULL, MODIFY spread DECIMAL(20,4) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Legacy monetary precision was normalized to currency precision and cannot be restored exactly.');
    }
}
