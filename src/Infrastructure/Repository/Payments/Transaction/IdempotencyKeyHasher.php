<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\Payments\Transaction;

use PenPay\Domain\Payments\ValueObject\IdempotencyKey;

final class IdempotencyKeyHasher
{
    /**
     * Return canonical SHA-256 hash for storing in DB.
     */
    public function hash(IdempotencyKey $key): string
    {
        return hash('sha256', (string)$key);
    }

    /**
     * Hash arbitrary string (helper).
     */
    public function hashString(string $value): string
    {
        return hash('sha256', $value);
    }
}