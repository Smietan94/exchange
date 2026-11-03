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
        // Before changing the column type, remove precision that the currency cannot represent.
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
