<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env.testing if it exists
if (file_exists(__DIR__ . '/../.env.testing')) {
    $lines = file(__DIR__ . '/../.env.testing', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        if (!str_contains($line, '=')) {
            continue;
        }
        
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, '"\'');
        
        // Don't override values already set in phpunit.xml
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

// Ensure test environment is set
$_ENV['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');

// Optional: Verify database connection at bootstrap
if (getenv('VERIFY_DB_CONNECTION') === 'true') {
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            $_ENV['DB_HOST'] ?? '127.0.0.1',
            $_ENV['DB_PORT'] ?? '3307',
            $_ENV['DB_DATABASE'] ?? 'penpay_test'
        );
        
        $pdo = new PDO(
            $dsn,
            $_ENV['DB_USERNAME'] ?? 'penpay',
            $_ENV['DB_PASSWORD'] ?? 'secret',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        
        if ($db !== ($_ENV['DB_DATABASE'] ?? 'penpay_test')) {
            throw new RuntimeException(
                "Connected to wrong database: {$db}. Expected: " . 
                ($_ENV['DB_DATABASE'] ?? 'penpay_test')
            );
        }
        
        echo "✅ Bootstrap: Connected to test database: {$db}\n";
    } catch (PDOException $e) {
        echo "❌ Bootstrap: Database connection failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}