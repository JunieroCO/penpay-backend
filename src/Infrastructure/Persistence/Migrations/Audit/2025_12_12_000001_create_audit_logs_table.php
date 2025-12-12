<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Migration\Audit;

use PenPay\Infrastructure\Persistence\Migration;

final class CreateAuditLogsTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE audit_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id CHAR(36) NULL COMMENT 'NULL for system actions',
                action VARCHAR(100) NOT NULL COMMENT 'LOGIN, DEPOSIT_INITIATED, etc',
                resource_type VARCHAR(50) COMMENT 'Transaction, User, etc',
                resource_id VARCHAR(36) COMMENT 'ID of affected resource',
                ip_address VARCHAR(45),
                user_agent TEXT,
                payload JSON COMMENT 'Request/response data',
                timestamp TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                
                INDEX idx_user_id (user_id, timestamp),
                INDEX idx_action (action),
                INDEX idx_resource (resource_type, resource_id),
                INDEX idx_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only audit trail (3-year retention)';
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS audit_logs");
    }
}