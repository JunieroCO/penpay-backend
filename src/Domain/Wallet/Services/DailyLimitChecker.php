<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Services;

use PenPay\Domain\Wallet\ValueObject\Money;

final class DailyLimitChecker implements DailyLimitCheckerInterface
{
    public function __construct(
        private readonly DailyLimitPolicyInterface $policy
    ) {}

    public function canDeposit(string $userId, Money $amount): bool
    {
        $todayTotal = $this->policy->amountDepositedToday($userId);
        $limit      = $this->policy->dailyDepositLimitForUser($userId);

        $projectedTotal = $todayTotal->add($amount);
        return !$projectedTotal->greaterThan($limit);
    }

    public function canWithdraw(string $userId, Money $amount): bool
    {
        $todayTotal = $this->policy->amountWithdrawnToday($userId);
        $limit      = $this->policy->dailyWithdrawalLimitForUser($userId);

        $projectedTotal = $todayTotal->add($amount);
        return !$projectedTotal->greaterThan($limit);
    }
}