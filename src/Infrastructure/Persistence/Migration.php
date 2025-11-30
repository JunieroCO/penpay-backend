<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence;

use PDO;
use PDOException;

abstract class Migration
{
    protected PDO $pdo;

    public function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'] ?? '127.0.0.1',
            $_ENV['DB_NAME']
        );

        $this->pdo = new PDO(
            $dsn,
            $_ENV['DB_USER'],
            $_ENV['DB_PASS'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    protected function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    abstract public function up(): void;
    abstract public function down(): void;
}