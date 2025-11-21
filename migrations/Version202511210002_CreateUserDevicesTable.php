<?php
declare(strict_types=1);

namespace PenPay\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version202511210002_CreateUserDevicesTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_devices table — max 2 devices per user, device-bound sessions';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('user_devices');

        $table->addColumn('id', 'integer', [
            'autoincrement' => true,
        ]);

        // Foreign key to users.id (binary)
        $table->addColumn('user_id', 'binary', [
            'length' => 16,
            'fixed' => true,
            'comment' => '(DC2Type:user_id)',
        ]);

        $table->addColumn('device_id', 'string', [
            'length' => 255,
        ]);

        $table->addColumn('platform', 'string', [
            'length' => 50,
        ]);

        $table->addColumn('model', 'string', [
            'length' => 100,
            'notnull' => false,
        ]);

        $table->addColumn('last_ip', 'string', [
            'length' => 45,
            'notnull' => false,
        ]);

        $table->addColumn('registered_at', 'datetime_immutable');

        // Primary key
        $table->setPrimaryKey(['id']);

        // Enforce max 2 devices + unique device per user
        $table->addUniqueIndex(['user_id', 'device_id'], 'uq_user_device');
        $table->addIndex(['user_id'], 'idx_user_devices_user_id');
        $table->addIndex(['device_id'], 'idx_user_devices_device_id');

        // Foreign key with CASCADE
        $table->addForeignKeyConstraint(
            'users',
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('user_devices');
    }
}