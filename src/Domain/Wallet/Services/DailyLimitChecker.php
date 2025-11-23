<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Services;

use PenPay\Domain\Shared\Kernel\Money;

/**
 * Domain service responsible for enforcing daily deposit limits.
 *
 * This service NEVER queries the database. Instead it depends on
 * an injected provider (policy) to tell it:
 *
 *   - how much has been deposited today
 *   - what the daily limit is
 *
 * This preserves pure-domain logic and follows PEC.
 */
final class DailyLimitChecker implements DailyLimitCheckerInterface
{
    public function __construct(
        private readonly DailyLimitPolicyInterface $policy
    ) {}

    public function canDeposit(string $userId, Money $amount): bool
    {
        $todayTotal = $this->policy->amountDepositedToday($userId);
        $limit      = $this->policy->dailyLimitForUser($userId);

        return $todayTotal->plus($amount)->lessThanOrEqual($limit);
    }
}