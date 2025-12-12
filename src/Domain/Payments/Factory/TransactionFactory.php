<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Factory;

use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use InvalidArgumentException;

final class TransactionFactory implements TransactionFactoryInterface
{
    // ===================================================================
    // DEPOSIT CREATION (PRECISION-SAFE METHODS)
    // ===================================================================

    public function createDepositFromCents(
        string $userId,
        int $amountUsdCents,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction {
        if ($amountUsdCents <= 0) {
            throw new InvalidArgumentException('Deposit amount must be positive');
        }

        $idempotencyKey ??= IdempotencyKey::generate();
        $transactionId = TransactionId::generate();
        $moneyUsd = Money::usd($amountUsdCents);

        return Transaction::initiateDeposit(
            $transactionId,
            $userId,
            $moneyUsd,
            $lockedRate,
            $idempotencyKey,
            $userDerivLoginId
        );
    }

    public function createDepositFromString(
        string $userId,
        string $amountUsd,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction {
        $cents = $this->parseCurrencyString($amountUsd);
        
        return $this->createDepositFromCents(
            $userId,
            $cents,
            $lockedRate,
            $userDerivLoginId,
            $idempotencyKey
        );
    }

    public function createDepositTransaction(
        string $userId,
        float $amountUsd,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction {
        trigger_error(
            'createDepositTransaction() is deprecated, use createDepositFromCents()',
            E_USER_DEPRECATED
        );
        
        $cents = (int) round($amountUsd * 100);
        
        return $this->createDepositFromCents(
            $userId,
            $cents,
            $lockedRate,
            $userDerivLoginId,
            $idempotencyKey
        );
    }

    // ===================================================================
    // WITHDRAWAL CREATION (PRECISION-SAFE METHODS)
    // ===================================================================

    public function createWithdrawalFromCents(
        string $userId,
        int $amountUsdCents,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction {
        if ($amountUsdCents <= 0) {
            throw new InvalidArgumentException('Withdrawal amount must be positive');
        }

        $idempotencyKey ??= IdempotencyKey::generate();
        $transactionId = TransactionId::generate();
        $moneyUsd = Money::usd($amountUsdCents);

        return Transaction::initiateWithdrawal(
            $transactionId,
            $userId,
            $moneyUsd,
            $lockedRate,
            $idempotencyKey,
            $userDerivLoginId
        );
    }

    public function createWithdrawalFromString(
        string $userId,
        string $amountUsd,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction {
        $cents = $this->parseCurrencyString($amountUsd);
        
        return $this->createWithdrawalFromCents(
            $userId,
            $cents,
            $lockedRate,
            $userDerivLoginId,
            $idempotencyKey
        );
    }

    /**
     * @deprecated Use createWithdrawalFromCents() or createWithdrawalFromString()
     */
    public function createWithdrawalTransaction(
        string $userId,
        float $amountUsd,
        LockedRate $lockedRate,
        string $userDerivLoginId,
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction {
        trigger_error(
            'createWithdrawalTransaction() is deprecated, use createWithdrawalFromCents()',
            E_USER_DEPRECATED
        );
        
        $cents = (int) round($amountUsd * 100);
        
        return $this->createWithdrawalFromCents(
            $userId,
            $cents,
            $lockedRate,
            $userDerivLoginId,
            $idempotencyKey
        );
    }

    private function parseCurrencyString(string $amount): int
    {
        $amount = trim($amount);

        // Validate format
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException(
                sprintf('Invalid USD amount format: "%s"', $amount)
            );
        }

        // Parse to cents
        if (str_contains($amount, '.')) {
            [$dollars, $cents] = explode('.', $amount);
            $cents = str_pad($cents, 2, '0', STR_PAD_RIGHT);
            return ((int) $dollars * 100) + (int) $cents;
        }

        return (int) $amount * 100;
    }
}