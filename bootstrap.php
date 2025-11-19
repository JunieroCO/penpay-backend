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

// Autowire application services, repositories, gateways
$containerBuilder->useAutowiring(true);
$containerBuilder->useAnnotations(false);

$container = $containerBuilder->build();

return $container;