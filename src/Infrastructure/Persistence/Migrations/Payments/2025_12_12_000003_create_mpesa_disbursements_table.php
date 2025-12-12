<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Migration\Payments;

use PenPay\Infrastructure\Persistence\Migration;

final class CreateMpesaDisbursementsTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE mpesa_disbursements (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                transaction_id CHAR(36) UNIQUE NOT NULL COMMENT 'Links to transactions.id',
                conversation_id VARCHAR(100) UNIQUE NOT NULL COMMENT 'Safaricom conversation ID',
                originator_conversation_id VARCHAR(100) UNIQUE NOT NULL COMMENT 'Our request ID',
                phone_number VARCHAR(20) NOT NULL COMMENT 'E.164 format',
                amount_kes_cents BIGINT NOT NULL COMMENT 'KES amount in cents',
                status ENUM('PENDING', 'COMPLETED', 'FAILED') NOT NULL DEFAULT 'PENDING',
                result_code VARCHAR(10),
                result_description TEXT,
                raw_payload JSON COMMENT 'Full B2C result payload',
                initiated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                completed_at TIMESTAMP(6) NULL,
                
                INDEX idx_transaction_id (transaction_id),
                INDEX idx_conversation (conversation_id),
                INDEX idx_phone (phone_number),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M-Pesa B2C disbursements (withdrawal evidence)';
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS mpesa_disbursements");
    }
}