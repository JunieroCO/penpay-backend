<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Factory;

use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Payments\Aggregate\Transaction;

interface TransactionFactoryInterface
{
    // ===================================================================
    // DEPOSIT CREATION (PRECISION-SAFE METHODS - RECOMMENDED)
    // ===================================================================

    /**
     * Create a deposit transaction from USD cents (precision-safe)
     * 
     * @param string $userId User ID
     * @param int $amountUsdCents Amount in USD cents (e.g., 10000 = $100.00)
     * @param LockedRate $lockedRate Locked FX rate for conversion
     * @param string $userDerivLoginId User's Deriv login ID
     * @param IdempotencyKey|null $idempotencyKey Optional idempotency key
     * @return Transaction
     * @throws \InvalidArgumentException if amount is invalid
     */
    public function createDepositFromCents(
        string $userId,
        int $amountUsdCents,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction;

    /**
     * Create a deposit transaction from USD string (precision-safe)
     * 
     * @param string $userId User ID
     * @param string $amountUsd Amount as string (e.g., "100.00" or "100")
     * @param LockedRate $lockedRate Locked FX rate for conversion
     * @param string $userDerivLoginId User's Deriv login ID
     * @param IdempotencyKey|null $idempotencyKey Optional idempotency key
     * @return Transaction
     * @throws \InvalidArgumentException if amount format is invalid
     */
    public function createDepositFromString(
        string $userId,
        string $amountUsd,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction;

    /**
     * @deprecated Use createDepositFromCents() or createDepositFromString() instead
     * Create a new deposit transaction (USD → KES via locked rate)
     */
    public function createDepositTransaction(
        string $userId,
        float $amountUsd,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction;

    // ===================================================================
    // WITHDRAWAL CREATION (PRECISION-SAFE METHODS - RECOMMENDED)
    // ===================================================================

    /**
     * Create a withdrawal transaction from USD cents (precision-safe)
     * 
     * @param string $userId User ID
     * @param int $amountUsdCents Amount in USD cents (e.g., 10000 = $100.00)
     * @param LockedRate $lockedRate Locked FX rate for conversion
     * @param string $userDerivLoginId User's Deriv login ID
     * @param string $withdrawalVerificationCode 6-character alphanumeric verification code
     * @param IdempotencyKey|null $idempotencyKey Optional idempotency key
     * @return Transaction
     * @throws \InvalidArgumentException if amount or verification code is invalid
     */
    public function createWithdrawalFromCents(
        string $userId,
        int $amountUsdCents,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        string $withdrawalVerificationCode,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction;

    /**
     * Create a withdrawal transaction from USD string (precision-safe)
     * 
     * @param string $userId User ID
     * @param string $amountUsd Amount as string (e.g., "100.00" or "100")
     * @param LockedRate $lockedRate Locked FX rate for conversion
     * @param string $userDerivLoginId User's Deriv login ID
     * @param string $withdrawalVerificationCode 6-character alphanumeric verification code
     * @param IdempotencyKey|null $idempotencyKey Optional idempotency key
     * @return Transaction
     * @throws \InvalidArgumentException if amount format or verification code is invalid
     */
    public function createWithdrawalFromString(
        string $userId,
        string $amountUsd,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        string $withdrawalVerificationCode,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction;

    /**
     * @deprecated Use createWithdrawalFromCents() or createWithdrawalFromString() instead
     * Create a new withdrawal transaction (USD → KES via locked rate)
     */
    public function createWithdrawalTransaction(
        string $userId,
        float $amountUsd,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        string $withdrawalVerificationCode,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction;
}