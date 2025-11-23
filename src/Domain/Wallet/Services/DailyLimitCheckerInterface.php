<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Services;

use PenPay\Domain\Wallet\ValueObject\Money;

interface DailyLimitCheckerInterface
{
    /**
     * Determines whether the user can deposit the given amount
     * according to daily limits defined in the domain.
     */
    public function canDeposit(string $userId, Money $amount): bool;
}