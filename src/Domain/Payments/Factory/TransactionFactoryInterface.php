<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Factory;

use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Payments\Aggregate\Transaction;

interface TransactionFactoryInterface
{
    /**
     * Create a new deposit transaction (USD → KES via locked rate)
     */
    public function createDepositTransaction(
        string $userId,
        float $amountUsd,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction;

    /**
     * Create a new withdrawal transaction (USD → KES via locked rate)
     */
    public function createWithdrawalTransaction(
        string $userId,
        float $amountUsd,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction;
}