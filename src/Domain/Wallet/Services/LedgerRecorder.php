<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Services;

use PenPay\Domain\Wallet\LedgerAccount;
use PenPay\Domain\Wallet\LedgerEntry;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\LockedRate;

class LedgerRecorder implements LedgerRecorderInterface
{
    public function recordCredit(
        LedgerAccount $account,
        Money $amount,
        TransactionId $transactionId
    ): LedgerAccount {
        $entry = LedgerEntry::credit(
            transactionId: $transactionId,
            amount: $amount
        );

        return $account->applyEntry($entry);
    }

    public function recordDebit(
        LedgerAccount $account,
        Money $amount,
        TransactionId $transactionId
    ): LedgerAccount {
        $entry = LedgerEntry::debit(
            transactionId: $transactionId,
            amount: $amount
        );

        return $account->applyEntry($entry);
    }

    public function recordDepositInitiated(
        string $userId,
        TransactionId $transactionId,
        Money $amountUsd,
        Money $amountKes,
        LockedRate $lockedRate
    ): void {
        // TODO: Implement deposit ledger recording logic
        // This might involve:
        // 1. Recording a pending deposit in a ledger table
        // 2. Creating journal entries for the deposit
        // 3. Logging the FX rate lock
        
        // Example implementation:
        // - Store the transaction ID and user ID for tracking
        // - Record both KES and USD amounts
        // - Save the locked exchange rate for reconciliation
        
        // Log the deposit initiation with FX rate details
        // You might want to persist this to a database or event store
    }
}