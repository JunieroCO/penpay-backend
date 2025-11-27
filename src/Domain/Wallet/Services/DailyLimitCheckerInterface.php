<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Services;

use PenPay\Domain\Wallet\ValueObject\Money;

interface DailyLimitCheckerInterface
{
    public function canDeposit(string $userId, Money $amount): bool;
    public function canWithdraw(string $userId, Money $amount): bool;
}