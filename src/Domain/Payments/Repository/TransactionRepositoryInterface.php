<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Repository;

use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
use PenPay\Domain\Shared\Kernel\TransactionId;
use DateTimeImmutable;

interface TransactionRepositoryInterface
{
    public function save(Transaction $transaction): void;

    public function findById(TransactionId $id): ?Transaction;

    public function getById(TransactionId $id): Transaction;

    public function findByIdempotencyKey(IdempotencyKey $key): ?Transaction;

    public function existsByIdempotencyKey(IdempotencyKey $key): bool;

    public function findByUserId(
        string $userId,
        ?int $limit = 50,
        ?int $offset = 0
    ): array;

    public function findByStatus(
        array $statuses,
        ?int $limit = 100
    ): array;

    public function getUserDailyTotal(
        string $userId,
        DateTimeImmutable $date
    ): int;

    public function countUserTransactionsForDate(
        string $userId,
        DateTimeImmutable $date
    ): int;

    public function findPendingRetry(
        int $maxRetries = 3,
        int $olderThanMinutes = 5
    ): array;

    public function findAwaitingConfirmation(
        int $olderThanMinutes = 2,
        ?int $limit = 50
    ): array;
}