<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts table for fund transfer domain';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE accounts (
                id            VARCHAR(36)     NOT NULL,
                owner_id      VARCHAR(36)     NOT NULL,
                currency      CHAR(3)         NOT NULL,
                balance       NUMERIC(18, 2)  NOT NULL DEFAULT 0,
                active        TINYINT(1)      NOT NULL DEFAULT 1,
                created_at    DATETIME(6)     NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                PRIMARY KEY (id),
                INDEX idx_accounts_owner (owner_id),
                INDEX idx_accounts_active (active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE accounts');
    }
}

