<?php
declare(strict_types=1);

use PenPay\Infrastructure\Persistence\Migration;

final class CreateDerivAccountsTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE deriv_accounts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                uuid CHAR(36) NOT NULL UNIQUE,
                user_id BIGINT UNSIGNED NOT NULL,
                loginid VARCHAR(32) NOT NULL,
                landing_company VARCHAR(50) NULL,
                landing_company_name VARCHAR(100) NULL,
                is_virtual TINYINT(1) NOT NULL DEFAULT 0,
                currency CHAR(3) NOT NULL DEFAULT 'USD',
                balance_usd_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY ux_user_loginid (user_id, loginid),
                INDEX idx_loginid (loginid),
                INDEX idx_virtual (is_virtual)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS deriv_accounts");
    }
}