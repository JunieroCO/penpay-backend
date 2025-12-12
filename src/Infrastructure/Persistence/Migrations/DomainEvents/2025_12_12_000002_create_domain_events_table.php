<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Migration\DomainEvents;

use PenPay\Infrastructure\Persistence\Migration;

final class CreateDomainEventsTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE domain_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id CHAR(36) UNIQUE NOT NULL COMMENT 'UUIDv7',
                aggregate_id CHAR(36) NOT NULL COMMENT 'User ID or Transaction ID',
                aggregate_type VARCHAR(50) NOT NULL COMMENT 'User, Transaction',
                event_type VARCHAR(100) NOT NULL COMMENT 'UserRegistered, TransactionCompleted, etc',
                payload JSON NOT NULL COMMENT 'Event data',
                occurred_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                published_at TIMESTAMP(6) NULL COMMENT 'NULL = not yet published',
                
                INDEX idx_aggregate (aggregate_id, aggregate_type),
                INDEX idx_event_type (event_type),
                INDEX idx_unpublished (published_at, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Domain event outbox';
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS domain_events");
    }
}