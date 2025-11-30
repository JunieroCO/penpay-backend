<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PenPay\Core\Container\Container;

// Boot container
$container = new Container();
(require __DIR__ . '/../src/Core/Container/bindings.php')($container);

// Your app (Slim, FastRoute, etc.)
$app = new \PenPay\Presentation\Http\App($container);
$app->run();