<?php
declare(strict_types=1);

use PenPay\Infrastructure\Persistence\Migration;

final class CreateUserAddressTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE user_address (
                user_id BIGINT UNSIGNED PRIMARY KEY,
                address_line_1 VARCHAR(255) NULL,
                address_line_2 VARCHAR(255) NULL,
                city VARCHAR(100) NULL,
                state VARCHAR(100) NULL,
                postcode VARCHAR(20) NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS user_address");
    }
}