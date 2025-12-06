<?php
declare(strict_types=1);

use PenPay\Infrastructure\Persistence\Migration;

final class CreateUserProfileTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE user_profile (
                user_id BIGINT UNSIGNED PRIMARY KEY,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                date_of_birth DATE NULL,
                country VARCHAR(100) NULL,
                country_code CHAR(2) NULL,
                residence VARCHAR(100) NULL,
                place_of_birth VARCHAR(100) NULL,
                calling_country_code VARCHAR(10) NULL,
                phone_country_code VARCHAR(10) NULL,
                email_consent TINYINT(1) NOT NULL DEFAULT 0,
                feature_flag_wallet TINYINT(1) NOT NULL DEFAULT 0,
                has_secret_answer TINYINT(1) NOT NULL DEFAULT 0,
                immutable_fields JSON NULL COMMENT 'Fields locked after KYC',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS user_profile");
    }
}