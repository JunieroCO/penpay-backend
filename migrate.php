<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Use test database credentials like in your test file
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3307';
$database = $_ENV['DB_DATABASE'] ?? 'penpay_test';  // Use test database
$username = $_ENV['DB_USERNAME'] ?? 'penpay';
$password = $_ENV['DB_PASSWORD'] ?? 'secret';

echo "=================================\n";
echo "  MIGRATING TO TEST DATABASE     \n";
echo "=================================\n";
echo "Host: {$host}:{$port}\n";
echo "Database: {$database}\n";
echo "=================================\n\n";

$migrations = [
    '2025_12_01_000001_create_users_table',
    '2025_12_01_000002_create_user_profile_table',
    '2025_12_01_000003_create_user_address_table',
    '2025_12_01_000004_create_user_compliance_table',
    '2025_12_01_000005_create_user_phone_verification_table',
    '2025_12_01_000006_create_deriv_accounts_table',
    '2025_12_01_000007_create_user_devices_table',
    '2025_12_01_000008_create_refresh_tokens_table',
];

echo "Running all migrations...\n\n";

foreach ($migrations as $migration) {
    // Try different paths
    $paths = [
        __DIR__ . "/Migrations/Users/{$migration}.php",
        __DIR__ . "/src/Infrastructure/Persistence/Migrations/Users/{$migration}.php",
        __DIR__ . "/Migrations/{$migration}.php",
        __DIR__ . "/src/Infrastructure/Persistence/Migrations/{$migration}.php",
    ];
    
    $found = false;
    foreach ($paths as $file) {
        if (file_exists($file)) {
            $found = true;
            echo "Running: " . basename($file) . "... ";
            
            try {
                require_once $file;
                
                // Extract class name from the actual file content
                $content = file_get_contents($file);
                preg_match('/\bclass\s+(\w+)/', $content, $matches);
                
                if (empty($matches[1])) {
                    echo "❌ No class found in file\n";
                    break;
                }
                
                $className = $matches[1];
                $migrationInstance = new $className();
                $migrationInstance->up();
                
                echo "✅ Success\n";
            } catch (Exception $e) {
                echo "❌ Error: " . $e->getMessage() . "\n";
            }
            break;
        }
    }
    
    if (!$found) {
        echo "⚠️  Skipping: {$migration} (file not found in any location)\n";
    }
}

echo "\nAll migrations completed!\n";