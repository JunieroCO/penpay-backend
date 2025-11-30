<?php
declare(strict_types=1);

use PenPay\Infrastructure\Persistence\Migration;

final class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                uuid CHAR(36) NOT NULL UNIQUE COMMENT 'Public identifier',
                email VARCHAR(255) NOT NULL UNIQUE,
                phone_e164 VARCHAR(20) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NULL,
                deriv_user_id BIGINT UNSIGNED NULL,
                preferred_language VARCHAR(10) NULL DEFAULT 'en_US',
                is_virtual TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Primary account type',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_email (email),
                INDEX idx_phone (phone_e164),
                INDEX idx_deriv_id (deriv_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS users");
    }
}