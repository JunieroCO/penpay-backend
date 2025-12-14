<?php
declare(strict_types=1);

namespace PenPay\Application\Payments\Service;

use PenPay\Domain\Wallet\ValueObject\LockedRate;

interface FxProviderInterface
{
    /**
     * Lock an FX rate for use in a transaction.
     * Should return a LockedRate (rate, from, to, lockedAt, expiresAt)
     */
    public function lockRate(string $fromCurrency, string $toCurrency): LockedRate;
}