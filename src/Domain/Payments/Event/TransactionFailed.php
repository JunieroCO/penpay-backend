<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Event;

use PenPay\Domain\Shared\Kernel\TransactionId;
use DateTimeImmutable;

final readonly class TransactionFailed
{
    public function __construct(
        public TransactionId $transactionId,
        public string $reason,
        public ?string $providerError,
        public DateTimeImmutable $failedAt
    ) {}
}