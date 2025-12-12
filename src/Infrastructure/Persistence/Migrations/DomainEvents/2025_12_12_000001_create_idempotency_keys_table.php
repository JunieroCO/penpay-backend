<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Migration\DomainEvents;

use PenPay\Infrastructure\Persistence\Migration;

final class CreateIdempotencyKeysTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE idempotency_keys (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                key_hash VARCHAR(64) UNIQUE NOT NULL COMMENT 'SHA-256 hash',
                transaction_id CHAR(36) UNIQUE NOT NULL COMMENT 'Result of idempotent operation',
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                expires_at TIMESTAMP(6) NOT NULL COMMENT 'Keys expire after 24 hours',
                
                INDEX idx_key_hash (key_hash),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Idempotency key registry';
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS idempotency_keys");
    }
}