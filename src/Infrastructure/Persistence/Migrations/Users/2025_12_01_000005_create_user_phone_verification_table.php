<?php
declare(strict_types=1);

use PenPay\Infrastructure\Persistence\Migration;

final class CreateUserPhoneVerificationTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE user_phone_verification (
                user_id BIGINT UNSIGNED PRIMARY KEY,
                phone VARCHAR(20) NULL,
                verified TINYINT(1) NOT NULL DEFAULT 0,
                last_verified_at TIMESTAMP NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS user_phone_verification");
    }
}