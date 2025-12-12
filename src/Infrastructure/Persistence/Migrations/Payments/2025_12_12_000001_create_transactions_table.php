<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Migration\Payments;

use PenPay\Infrastructure\Persistence\Migration;

final class CreateTransactionsTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE transactions (
                id CHAR(36) PRIMARY KEY COMMENT 'UUIDv7',
                user_id CHAR(36) NOT NULL,
                type ENUM('DEPOSIT', 'WITHDRAWAL') NOT NULL,
                state ENUM(
                    'PENDING',
                    'PROCESSING',
                    'AWAITING_MPESA_CALLBACK',
                    'AWAITING_DERIV_CONFIRMATION',
                    'AWAITING_MPESA_DISBURSEMENT',
                    'COMPLETED',
                    'FAILED',
                    'REVERSED'
                ) NOT NULL DEFAULT 'PENDING',
                
                -- Amounts (stored as cents)
                amount_usd_cents BIGINT NOT NULL COMMENT 'USD amount in cents',
                amount_kes_cents BIGINT NOT NULL COMMENT 'KES amount in cents',
                
                -- FX rate (locked at transaction creation)
                fx_rate DECIMAL(10,4) NOT NULL COMMENT 'Rate used for conversion',
                fx_rate_source VARCHAR(100) NOT NULL COMMENT 'exchangerate.host, fixer.io, etc',
                fx_rate_timestamp TIMESTAMP(6) NOT NULL COMMENT 'When rate was fetched',
                
                -- Idempotency
                idempotency_key_hash VARCHAR(64) UNIQUE NOT NULL COMMENT 'SHA-256 of client key',
                
                -- Failure tracking
                failure_reason TEXT NULL,
                retry_count INT DEFAULT 0,
                
                -- Timestamps
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                completed_at TIMESTAMP(6) NULL,
                failed_at TIMESTAMP(6) NULL,
                
                INDEX idx_user_id (user_id),
                INDEX idx_type (type),
                INDEX idx_state (state),
                INDEX idx_created_at (created_at),
                INDEX idx_idempotency (idempotency_key_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Transaction aggregate (deposit + withdrawal)';
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS transactions");
    }
}