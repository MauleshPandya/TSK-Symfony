<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create transfers ledger table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE transfers (
                id               VARCHAR(36)     NOT NULL,
                from_account_id  VARCHAR(36)     NOT NULL,
                to_account_id    VARCHAR(36)     NOT NULL,
                amount           NUMERIC(18, 2)  NOT NULL,
                currency         CHAR(3)         NOT NULL,
                status           VARCHAR(20)     NOT NULL,
                description      VARCHAR(500)    NULL,
                failure_reason   VARCHAR(500)    NULL,
                idempotency_key  VARCHAR(50)     NULL,
                created_at       DATETIME(6)     NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                completed_at     DATETIME(6)     NULL COMMENT "(DC2Type:datetime_immutable)",
                PRIMARY KEY (id),
                INDEX idx_transfers_from_account (from_account_id),
                INDEX idx_transfers_to_account   (to_account_id),
                INDEX idx_transfers_status       (status),
                UNIQUE INDEX uniq_transfers_idempotency (idempotency_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE transfers');
    }
}

