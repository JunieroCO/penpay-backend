<?php
declare(strict_types=1);

namespace PenPay\Application\Auth\Contract;

use PenPay\Application\Auth\DTO\RefreshTokenRequest;
use PenPay\Application\Auth\DTO\AuthResponse;

interface RefreshTokenHandlerInterface
{
    public function handle(RefreshTokenRequest $request): AuthResponse;
}

