<?php
declare(strict_types=1);

use DI\ContainerBuilder;

require __DIR__ . '/vendor/autoload.php';

$containerBuilder = new ContainerBuilder();

// Load configuration files
$containerBuilder->addDefinitions([
    'config.db' => [
        'host' => getenv('DB_HOST'),
        'user' => getenv('DB_USER'),
        'pass' => getenv('DB_PASS'),
        'name' => getenv('DB_NAME'),
    ],
    'config.redis' => [
        'host' => getenv('REDIS_HOST'),
        'port' => getenv('REDIS_PORT') ?: 6379,
    ],
    // ADD THIS — THE EMPIRE'S FINAL COMMAND
    'config.deriv' => [
        'app_id' => getenv('DERIV_APP_ID') ?: '1089',
        'ws_url' => 'wss://ws.binaryws.com/websockets/v3?app_id=' . (getenv('DERIV_APP_ID') ?: '1089'),
        'payment_agent_token' => getenv('DERIV_PAYMENT_AGENT_TOKEN'),
    ],
]);

// Enable autowiring (safe in PHP-DI v7)
$containerBuilder->useAutowiring(true);

$container = $containerBuilder->build();

return $container;