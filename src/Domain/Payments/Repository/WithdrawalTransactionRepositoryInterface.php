<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Repository;

use PenPay\Domain\Payments\Aggregate\WithdrawalTransaction;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;

interface WithdrawalTransactionRepositoryInterface
{
    public function save(WithdrawalTransaction $transaction): void;

    public function getById(TransactionId $id): WithdrawalTransaction;

    public function findByIdempotencyKey(IdempotencyKey $key): ?WithdrawalTransaction;
}