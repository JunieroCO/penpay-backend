<?php
declare(strict_types=1);

use PenPay\Infrastructure\Persistence\Migration;

final class CreateRefreshTokensTable extends Migration
{
    public function up(): void
    {
        $this->exec("
            CREATE TABLE refresh_tokens (
                -- ULID as string (26 chars) for simplicity
                id CHAR(26) PRIMARY KEY COMMENT 'ULID as string',
                
                -- Foreign key to users.id — must match users.id type
                user_id BIGINT UNSIGNED NOT NULL,
                
                -- Device fingerprint — immutable binding
                device_id VARCHAR(255) NOT NULL,
                
                -- Hashed refresh token (never store plaintext)
                token_hash VARCHAR(128) NOT NULL COMMENT 'SHA-512 hash of refresh token',
                
                -- Token family identifier — enables instant revocation of all tokens in family
                family CHAR(36) NOT NULL COMMENT 'Token family identifier',
                
                expires_at TIMESTAMP NOT NULL,
                
                revoked TINYINT(1) NOT NULL DEFAULT 0,
                
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                
                last_used_at TIMESTAMP NULL,
                
                -- Critical indexes
                UNIQUE KEY uniq_refresh_token_hash (token_hash),
                INDEX idx_refresh_user_id (user_id),
                INDEX idx_refresh_user_device (user_id, device_id),
                INDEX idx_refresh_family (family),
                INDEX idx_refresh_expires_at (expires_at),
                INDEX idx_refresh_cleanup (revoked, expires_at),
                
                -- Foreign key with CASCADE delete
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS refresh_tokens");
    }
}