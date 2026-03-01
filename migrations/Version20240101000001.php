<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE accounts (
                id          VARCHAR(36)   NOT NULL COMMENT "UUID",
                owner_id    VARCHAR(100)  NOT NULL COMMENT "External user/owner identifier",
                currency    CHAR(3)       NOT NULL COMMENT "ISO 4217 currency code",
                balance     VARCHAR(20)   NOT NULL DEFAULT "0.00" COMMENT "Decimal stored as string for precision",
                active      TINYINT(1)    NOT NULL DEFAULT 1,
                version     INT           NOT NULL DEFAULT 0 COMMENT "Optimistic lock version",
                created_at  DATETIME(6)   NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                updated_at  DATETIME(6)   NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                PRIMARY KEY (id),
                INDEX idx_accounts_owner (owner_id),
                INDEX idx_accounts_currency (currency)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE accounts');
    }
}
