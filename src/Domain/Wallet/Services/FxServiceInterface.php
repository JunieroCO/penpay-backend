<?php
namespace PenPay\Domain\Wallet\Services;

use PenPay\Domain\Wallet\ValueObject\LockedRate;

interface FxServiceInterface
{
    /**
     * Lock and return the FX rate for a short TTL.
     */
    public function lockRate(string $fromCurrency, string $toCurrency): LockedRate;
}