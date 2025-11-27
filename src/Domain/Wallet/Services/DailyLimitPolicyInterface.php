<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Services;

use PenPay\Domain\Wallet\ValueObject\Money;

interface DailyLimitPolicyInterface
{
    public function amountDepositedToday(string $userId): Money;
    public function amountWithdrawnToday(string $userId): Money;
    public function dailyDepositLimitForUser(string $userId): Money;
    public function dailyWithdrawalLimitForUser(string $userId): Money;
}