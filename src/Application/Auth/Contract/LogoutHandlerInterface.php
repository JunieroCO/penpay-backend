<?php
declare(strict_types=1);

namespace PenPay\Application\Auth\Contract;

use PenPay\Application\Auth\DTO\LogoutRequest;

interface LogoutHandlerInterface
{
    public function handle(LogoutRequest $request): void;
}

