<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Migration\Payments;

use PenPay\Infrastructure\Persistence\Migration;

final class CreateMpesaRequestsTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE mpesa_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                transaction_id CHAR(36) UNIQUE NOT NULL COMMENT 'Links to transactions.id',
                mpesa_receipt_number VARCHAR(50) UNIQUE NOT NULL COMMENT 'Safaricom receipt',
                phone_number VARCHAR(20) NOT NULL COMMENT 'E.164 format',
                amount_kes_cents BIGINT NOT NULL COMMENT 'KES amount in cents',
                transaction_date TIMESTAMP(6) NOT NULL COMMENT 'From M-Pesa callback',
                raw_payload JSON NOT NULL COMMENT 'Full callback payload',
                received_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                
                INDEX idx_transaction_id (transaction_id),
                INDEX idx_receipt (mpesa_receipt_number),
                INDEX idx_phone (phone_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M-Pesa STK Push callbacks (deposit evidence)';
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS mpesa_requests");
    }
}