<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Services;

use PenPay\Domain\Wallet\ValueObject\Money;

interface DailyLimitPolicyInterface
{
    /**
     * Amount deposited today (derived externally, likely DB aggregate)
     */
    public function amountDepositedToday(string $userId): Money;

    /**
     * Max allowed daily deposit amount for the user.
     * Could be static or KYC-tier based.
     */
    public function dailyLimitForUser(string $userId): Money;
}