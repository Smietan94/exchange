<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wallet archival timestamp for safe removal without deleting transaction history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wallets ADD deleted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping deleted_at would make archived wallets active again.');
    }
}
