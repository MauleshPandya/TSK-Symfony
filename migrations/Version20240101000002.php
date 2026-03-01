<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create transfers table (ledger)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE transfers (
                id                  VARCHAR(36)     NOT NULL COMMENT "UUID",
                from_account_id     VARCHAR(36)     NOT NULL,
                to_account_id       VARCHAR(36)     NOT NULL,
                amount              VARCHAR(20)     NOT NULL COMMENT "Decimal string, bcmath precision",
                currency            CHAR(3)         NOT NULL,
                status              VARCHAR(20)     NOT NULL DEFAULT "pending",
                idempotency_key     VARCHAR(36)     NOT NULL COMMENT "UUID from client",
                description         VARCHAR(500)    NULL,
                failure_reason      VARCHAR(255)    NULL,
                created_at          DATETIME(6)     NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                completed_at        DATETIME(6)     NULL COMMENT "(DC2Type:datetime_immutable)",
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_transfers_idempotency (idempotency_key),
                INDEX idx_transfers_from (from_account_id),
                INDEX idx_transfers_to (to_account_id),
                INDEX idx_transfers_status (status),
                INDEX idx_transfers_created (created_at),
                CONSTRAINT fk_transfers_from FOREIGN KEY (from_account_id) REFERENCES accounts (id),
                CONSTRAINT fk_transfers_to FOREIGN KEY (to_account_id) REFERENCES accounts (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE transfers');
    }
}
