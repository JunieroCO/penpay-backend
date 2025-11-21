<?php
declare(strict_types=1);

namespace PenPay\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version202511210003_CreateRefreshTokensTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create refresh_tokens table — device-bound, rotation-aware, revocation-ready, CBK compliant';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('refresh_tokens');

        // ULID as binary(16) → 128-bit, time-ordered, URL-safe, 2× faster than string
        $table->addColumn('id', 'binary', [
            'length' => 16,
            'fixed' => true,
            'comment' => '(DC2Type:ulid)',
        ]);

        // Foreign key to users.id — binary(16)
        $table->addColumn('user_id', 'binary', [
            'length' => 16,
            'fixed' => true,
            'comment' => '(DC2Type:user_id)',
        ]);

        // Device fingerprint — immutable binding
        $table->addColumn('device_id', 'string', [
            'length' => 255,
        ]);

        // Hashed refresh token (never store plaintext)
        $table->addColumn('token_hash', 'string', [
            'length' => 128, // SHA-512 hex = 128 chars
            'comment' => 'SHA-512 hash of refresh token',
        ]);

        $table->addColumn('family', 'string', [
            'length' => 36,
            'comment' => 'Token family identifier — enables instant revocation of all tokens in family',
        ]);

        $table->addColumn('expires_at', 'datetime_immutable');

        $table->addColumn('revoked', 'boolean', [
            'default' => false,
        ]);

        $table->addColumn('created_at', 'datetime_immutable', [
            'default' => 'CURRENT_TIMESTAMP',
        ]);

        $table->addColumn('last_used_at', 'datetime_immutable', [
            'notnull' => false,
        ]);

        // Primary key
        $table->setPrimaryKey(['id']);

        // Critical indexes
        $table->addUniqueIndex(['token_hash'], 'uniq_refresh_token_hash');     // O(1) lookup
        $table->addIndex(['user_id'], 'idx_refresh_user_id');
        $table->addIndex(['user_id', 'device_id'], 'idx_refresh_user_device');
        $table->addIndex(['family'], 'idx_refresh_family');                    // instant revocation
        $table->addIndex(['expires_at'], 'idx_refresh_expires_at');            // cleanup job
        $table->addIndex(['revoked', 'expires_at'], 'idx_refresh_cleanup');

        // Foreign key with CASCADE delete
        $table->addForeignKeyConstraint(
            'users',
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_refresh_token_user'
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('refresh_tokens');
    }

    // Run after users + user_devices
    public function isTransactional(): bool
    {
        return true;
    }
}