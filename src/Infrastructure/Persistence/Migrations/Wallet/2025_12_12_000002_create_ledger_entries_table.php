<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Migration\Wallet;

use PenPay\Infrastructure\Persistence\Migration;

final class CreateLedgerEntriesTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE ledger_entries (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                transaction_id CHAR(36) NOT NULL COMMENT 'Links to transactions.id',
                debit_account_id BIGINT UNSIGNED NOT NULL,
                credit_account_id BIGINT UNSIGNED NOT NULL,
                amount_cents BIGINT NOT NULL COMMENT 'Amount in cents (currency from account)',
                currency CHAR(3) NOT NULL,
                description VARCHAR(255) NOT NULL,
                recorded_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                
                INDEX idx_transaction_id (transaction_id),
                INDEX idx_debit_account (debit_account_id, recorded_at),
                INDEX idx_credit_account (credit_account_id, recorded_at),
                
                CONSTRAINT chk_different_accounts CHECK (debit_account_id != credit_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only double-entry ledger';
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS ledger_entries");
    }
}