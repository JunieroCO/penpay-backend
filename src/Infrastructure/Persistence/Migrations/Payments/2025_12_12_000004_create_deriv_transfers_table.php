<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Migration\Payments;

use PenPay\Infrastructure\Persistence\Migration;

final class CreateDerivTransfersTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE deriv_transfers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                transaction_id CHAR(36) UNIQUE NOT NULL COMMENT 'Links to transactions.id',
                deriv_transfer_id VARCHAR(100) UNIQUE NOT NULL COMMENT 'Deriv transaction ID',
                from_login_id VARCHAR(50) NOT NULL COMMENT 'Source Deriv account (PA)',
                to_login_id VARCHAR(50) NOT NULL COMMENT 'Destination Deriv account (user)',
                amount_usd_cents BIGINT NOT NULL COMMENT 'USD amount in cents',
                executed_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                raw_payload JSON COMMENT 'Full Deriv response',
                
                INDEX idx_transaction_id (transaction_id),
                INDEX idx_deriv_transfer (deriv_transfer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Deriv payment_agent_transfer (deposit funding)';
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS deriv_transfers");
    }
}