<?php
declare(strict_types=1);

use PenPay\Infrastructure\Persistence\Migration;

final class CreateUserComplianceTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE user_compliance (
                user_id BIGINT UNSIGNED PRIMARY KEY,
                employment_status VARCHAR(100) NULL,
                account_opening_reason VARCHAR(255) NULL,
                fatca_declaration TINYINT(1) NOT NULL DEFAULT 0,
                non_pep_declaration TINYINT(1) NOT NULL DEFAULT 0,
                tin_number VARCHAR(100) NULL,
                tax_residence CHAR(2) NULL,
                tin_skipped TINYINT(1) NOT NULL DEFAULT 0,
                is_authenticated_payment_agent TINYINT(1) NOT NULL DEFAULT 0,
                request_professional_status TINYINT(1) NOT NULL DEFAULT 0,
                dxtrade_user_exception TINYINT(1) NOT NULL DEFAULT 0,
                tnc_status JSON NULL DEFAULT '{}',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS user_compliance");
    }
}