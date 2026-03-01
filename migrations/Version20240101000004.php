<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create webhook_endpoints and webhook_deliveries tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE webhook_endpoints (
                id                  VARCHAR(36)     NOT NULL,
                url                 VARCHAR(500)    NOT NULL,
                signing_secret_hash VARCHAR(64)     NOT NULL COMMENT "SHA-256 hash of raw secret",
                events              JSON            NOT NULL COMMENT "Subscribed event types",
                description         VARCHAR(100)    NULL,
                active              TINYINT(1)      NOT NULL DEFAULT 1,
                total_deliveries    INT             NOT NULL DEFAULT 0,
                failed_deliveries   INT             NOT NULL DEFAULT 0,
                last_delivery_at    DATETIME(6)     NULL COMMENT "(DC2Type:datetime_immutable)",
                created_at          DATETIME(6)     NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                PRIMARY KEY (id),
                INDEX idx_webhooks_active (active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE webhook_deliveries (
                id              VARCHAR(36)     NOT NULL,
                endpoint_id     VARCHAR(36)     NOT NULL,
                event_type      VARCHAR(50)     NOT NULL,
                resource_id     VARCHAR(36)     NOT NULL,
                payload         TEXT            NOT NULL,
                success         TINYINT(1)      NOT NULL,
                http_status_code INT            NULL,
                response_body   TEXT            NULL,
                error_message   VARCHAR(500)    NULL,
                attempt_number  INT             NOT NULL DEFAULT 1,
                duration_ms     INT             NOT NULL DEFAULT 0,
                created_at      DATETIME(6)     NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                PRIMARY KEY (id),
                INDEX idx_delivery_endpoint (endpoint_id, created_at),
                INDEX idx_delivery_event    (event_type),
                INDEX idx_delivery_success  (success),
                CONSTRAINT fk_delivery_endpoint FOREIGN KEY (endpoint_id) REFERENCES webhook_endpoints (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook_deliveries');
        $this->addSql('DROP TABLE webhook_endpoints');
    }
}
