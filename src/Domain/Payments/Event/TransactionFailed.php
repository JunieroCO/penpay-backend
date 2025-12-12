<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Event;

use PenPay\Domain\Shared\Kernel\TransactionId;
use DateTimeImmutable;

final readonly class TransactionFailed
{
    public function __construct(
        public TransactionId $transactionId,
        public string $userId,
        public string $reason,
        public ?string $providerError,
        public DateTimeImmutable $failedAt
    ) {}

    public function toArray(): array
    {
        return [
            'event_type' => 'TransactionFailed',
            'transaction_id' => (string) $this->transactionId,
            'user_id' => $this->userId,
            'reason' => $this->reason,
            'provider_error' => $this->providerError,
            'failed_at' => $this->failedAt->format(DATE_ATOM),
        ];
    }
}