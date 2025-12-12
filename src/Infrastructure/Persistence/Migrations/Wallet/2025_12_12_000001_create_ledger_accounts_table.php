<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Migration\Wallet;

use PenPay\Infrastructure\Persistence\Migration;

final class CreateLedgerAccountsTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE ledger_accounts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                currency CHAR(3) NOT NULL COMMENT 'USD or KES',
                account_type ENUM('USER', 'PENPAY_FLOAT', 'PENPAY_POOL') NOT NULL,
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                
                UNIQUE KEY unique_user_currency_type (user_id, currency, account_type),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ledger accounts for double-entry bookkeeping';
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS ledger_accounts");
    }
}