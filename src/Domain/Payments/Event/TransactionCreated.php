<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Event;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
use DateTimeImmutable;

final readonly class TransactionCreated
{
    public function __construct(
        public TransactionId $transactionId,
        public string $userId,
        public string $type, // "deposit" or "withdrawal"
        public Money $amount,
        public IdempotencyKey $idempotencyKey,
        public DateTimeImmutable $occurredAt
    ) {}
}