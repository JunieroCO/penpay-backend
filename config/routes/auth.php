<?php
declare(strict_types=1);

use PenPay\Presentation\Http\Controllers\AuthController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// This file is loaded by your PSR-15 pipeline or Slim/Mezzio app
return static function ($app): void {
    // ====================================================================
    // AUTH ENDPOINTS — PUBLIC (NO AUTH REQUIRED)
    // ====================================================================

    $app->post('/api/v1/auth/login', [AuthController::class, 'login'])
        ->setName('api.auth.login');

    $app->post('/api/v1/auth/refresh', [AuthController::class, 'refresh'])
        ->setName('api.auth.refresh');

    $app->post('/api/v1/auth/logout', [AuthController::class, 'logout'])
        ->setName('api.auth.logout');

    // ====================================================================
    // OPTIONAL: Future endpoints (already planned, just waiting for you)
    // ====================================================================

    // $app->post('/api/v1/auth/password-reset/request', [PasswordResetController::class, 'request']);
    // $app->post('/api/v1/auth/password-reset/verify', [PasswordResetController::class, 'verify']);
    // $app->post('/api/v1/auth/2fa/enable', [TwoFactorController::class, 'enable']);
    // $app->post('/api/v1/auth/2fa/verify', [TwoFactorController::class, 'verify']);
};