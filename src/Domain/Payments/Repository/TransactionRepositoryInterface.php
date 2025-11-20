<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Repository;

use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;

interface TransactionRepositoryInterface
{
    public function save(Transaction $transaction): void;

    public function findById(TransactionId $id): ?Transaction;

    /** @throws \DomainException if not found */
    public function getById(TransactionId $id): Transaction;

    public function findByIdempotencyKey(IdempotencyKey $key): ?Transaction;

    public function existsByIdempotencyKey(IdempotencyKey $key): bool;
}