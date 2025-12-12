<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Migration\Admin;

use PenPay\Infrastructure\Persistence\Migration;

final class CreateAdminUsersTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE admin_users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL COMMENT 'Argon2ID',
                role ENUM('SUPER_ADMIN', 'SUPPORT') NOT NULL,
                totp_secret_encrypted VARCHAR(255) COMMENT 'AES-256-GCM encrypted',
                ip_allowlist JSON COMMENT 'Array of CIDR blocks',
                is_active BOOLEAN DEFAULT TRUE,
                last_login_at TIMESTAMP(6) NULL,
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                
                INDEX idx_email (email),
                INDEX idx_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Admin user accounts';
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS admin_users");
    }
}