<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow one active wallet per user and currency while retaining archived wallets';
    }

    public function up(Schema $schema): void
    {
        // MariaDB permits multiple NULL values in a UNIQUE index. Archived wallets
        // therefore stop participating in uniqueness, while active wallets remain protected.
        $this->addSql(<<<'SQL'
            ALTER TABLE wallets
                ADD active_currency VARCHAR(3)
                    GENERATED ALWAYS AS (IF(deleted_at IS NULL, currency, NULL)) PERSISTENT,
                DROP INDEX wallet_user_currency_unique,
                ADD UNIQUE INDEX wallet_user_active_currency_unique (user_id, active_currency)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Restoring uniqueness across archived wallets can fail when a user has recreated a wallet currency.',
        );
    }
}
