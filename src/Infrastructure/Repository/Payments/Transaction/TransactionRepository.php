<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\Payments\Transaction;

use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;
use DomainException;

final class TransactionRepository implements TransactionRepositoryInterface
{
    public function __construct(
        private readonly TransactionWriteRepository $write,
        private readonly TransactionReadRepository $read,
    ) {}

    public function save(Transaction $transaction): void
    {
        $this->write->save($transaction);
    }

    public function findById(TransactionId $id): ?Transaction
    {
        return $this->read->findById($id);
    }

    public function getById(TransactionId $id): Transaction
    {
        $tx = $this->read->findById($id);
        if ($tx === null) {
            throw new DomainException("Transaction {$id} not found");
        }
        return $tx;
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?Transaction
    {
        return $this->read->findByIdempotencyKey($key);
    }

    public function existsByIdempotencyKey(IdempotencyKey $key): bool
    {
        return $this->read->existsByIdempotencyKey($key);
    }

    public function findByUserId(string $userId, ?int $limit = 50, ?int $offset = 0): array
    {
        return $this->read->findByUserId($userId, $limit, $offset);
    }

    public function findByStatus(array $statuses, ?int $limit = 100): array
    {
        return $this->read->findByStatus($statuses, $limit);
    }

    public function getUserDailyTotal(string $userId, \DateTimeImmutable $date): int
    {
        return $this->read->getUserDailyTotal($userId, $date);
    }

    public function countUserTransactionsForDate(string $userId, \DateTimeImmutable $date): int
    {
        return $this->read->countUserTransactionsForDate($userId, $date);
    }

    public function findPendingRetry(int $maxRetries = 3, int $olderThanMinutes = 5): array
    {
        return $this->read->findPendingRetry($maxRetries, $olderThanMinutes);
    }

    public function findAwaitingConfirmation(int $olderThanMinutes = 2, ?int $limit = 50): array
    {
        return $this->read->findAwaitingConfirmation($olderThanMinutes, $limit);
    }
}