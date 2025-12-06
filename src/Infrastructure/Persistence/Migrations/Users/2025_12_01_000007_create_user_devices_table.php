<?php
declare(strict_types=1);

use PenPay\Infrastructure\Persistence\Migration;

final class CreateUserDevicesTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE user_devices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                device_id VARCHAR(255) NOT NULL,
                platform VARCHAR(50) NOT NULL,
                model VARCHAR(100) NULL,
                last_ip VARCHAR(45) NULL,
                registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_device_id (device_id),
                UNIQUE KEY uq_user_device (user_id, device_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS user_devices");
    }
}