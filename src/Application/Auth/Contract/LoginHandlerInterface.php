<?php
declare(strict_types=1);

namespace PenPay\Application\Auth\Contract;

use PenPay\Application\Auth\DTO\LoginRequest;
use PenPay\Application\Auth\DTO\AuthResponse;

interface LoginHandlerInterface
{
    public function handle(LoginRequest $request): AuthResponse;
}

