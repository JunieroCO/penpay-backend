<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Repository;

use PenPay\Domain\Wallet\Aggregate\LedgerAccount;

interface LedgerRepositoryInterface
{
    /** Load or create ledger for user (always exists) */
    public function ofUser(string $userId): LedgerAccount;

    /** Persist ledger account + release events */
    public function save(LedgerAccount $account): void;

    /** Optional: for analytics */
    public function getTotalFloatUsd(): int;

    public function getTotalFloatKes(): int;
}