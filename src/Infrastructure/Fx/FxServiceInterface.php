<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Fx;

use PenPay\Domain\Wallet\ValueObject\LockedRate;

interface FxServiceInterface
{
    public function lockRate(string $from, string $to): LockedRate;
}