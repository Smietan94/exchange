<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store rates at 20 decimal places; for supported inputs below 10^11 this bounds rate quantization error below 5e-10 target currency units';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions MODIFY exchange_rate DECIMAL(30,20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Exchange rates recorded at 20 decimal places cannot be reduced to the legacy 6-place precision without losing audit information.');
    }
}
