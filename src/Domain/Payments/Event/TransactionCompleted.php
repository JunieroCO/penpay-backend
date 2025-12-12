<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Event;

use PenPay\Domain\Shared\Kernel\TransactionId;
use DateTimeImmutable;

final readonly class TransactionCompleted
{
    public function __construct(
        public TransactionId $transactionId,
        public string $userId,
        public ?string $derivTransferId,
        public ?string $derivTxnId,
        public DateTimeImmutable $completedAt
    ) {}

    public function toArray(): array
    {
        return [
            'event_type' => 'TransactionCompleted',
            'transaction_id' => (string) $this->transactionId,
            'user_id' => $this->userId,
            'deriv_transfer_id' => $this->derivTransferId,
            'deriv_txn_id' => $this->derivTxnId,
            'completed_at' => $this->completedAt->format(DATE_ATOM),
        ];
    }
}