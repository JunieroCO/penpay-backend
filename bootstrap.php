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
        'port' => getenv('REDIS_PORT'),
    ]
]);

// Enable autowiring (safe in PHP-DI v7)
$containerBuilder->useAutowiring(true);

$container = $containerBuilder->build();

return $container;