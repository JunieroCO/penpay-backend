<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Services;

use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;

interface LedgerRecorderInterface
{
    public function recordDepositInitiated(
        string $userId,
        TransactionId $transactionId,
        Money $amountUsd,
        Money $amountKes,
        LockedRate $lockedRate
    ): void;

    public function recordWithdrawalInitiated(
        string $userId,
        TransactionId $transactionId,
        Money $amountUsd,
        LockedRate $lockedRate
    ): void;
}