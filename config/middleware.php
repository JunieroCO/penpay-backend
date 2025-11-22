<?php
declare(strict_types=1);

use PenPay\Presentation\Http\Middleware\RateLimitMiddleware;
use PenPay\Presentation\Http\Middleware\SecurityHeadersMiddleware;
use Slim\App;
use Psr\Container\ContainerInterface;

return static function (App $app): void {
    /** @var ContainerInterface $container */
    $container = $app->getContainer();
    
    // Global middleware (applies to ALL routes)
    $app->add(SecurityHeadersMiddleware::class);

    // Auth-specific rate limiting group
    $app->group('/api/v1/auth', function ($group) {
        // Your auth routes already defined
    })->add(new RateLimitMiddleware(
        redis: $container->get(Redis::class),
        limit: 60,
        window: 60
    ));

    // Login gets softer limit (replace YourController::class with your actual controller)
    $app->post('/api/v1/auth/login', [YourController::class, 'login'])
        ->add(new RateLimitMiddleware(
            redis: $container->get(Redis::class),
            limit: 10,
            window: 60
        ));
};