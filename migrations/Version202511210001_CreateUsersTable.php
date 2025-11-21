<?php
declare(strict_types=1);

namespace PenPay\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version202511210001_CreateUsersTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users table — User aggregate root with binary UserId and embedded KYC';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('users');

        // UserId as binary(16) — fastest possible UUID v7 storage
        $table->addColumn('id', 'binary', [
            'length' => 16,
            'fixed' => true,
            'comment' => '(DC2Type:user_id)',
        ]);

        $table->addColumn('email', 'string', [
            'length' => 255,
            'comment' => '(DC2Type:email)',
        ]);

        $table->addColumn('phone_number', 'string', [
            'length' => 20,
            'comment' => '(DC2Type:phone_number)',
        ]);

        $table->addColumn('deriv_login_id', 'integer', [
            'comment' => '(DC2Type:deriv_login_id)',
        ]);

        $table->addColumn('password_hash', 'string', [
            'length' => 255,
            'comment' => '(DC2Type:password_hash)',
        ]);

        $table->addColumn('kyc_snapshot', 'json', [
            'notnull' => false,
            'comment' => '(DC2Type:kyc_snapshot)',
        ]);

        $table->addColumn('created_at', 'datetime_immutable', [
            'default' => 'CURRENT_TIMESTAMP',
        ]);

        // Primary key
        $table->setPrimaryKey(['id']);

        // Unique invariants — CBK audit requirement
        $table->addUniqueIndex(['email'], 'uq_user_email');
        $table->addUniqueIndex(['phone_number'], 'uq_user_phone');
        $table->addUniqueIndex(['deriv_login_id'], 'uq_user_deriv_login_id');

        // Fast lookups
        $table->addIndex(['deriv_login_id'], 'idx_user_deriv_login_id');
        $table->addIndex(['phone_number'], 'idx_user_phone');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('users');
    }
}