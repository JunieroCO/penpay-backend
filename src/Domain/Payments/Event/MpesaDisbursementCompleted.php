<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Event;

use PenPay\Domain\Shared\Kernel\TransactionId;
use DateTimeImmutable;

final readonly class MpesaDisbursementCompleted
{
    public function __construct(
        public TransactionId $transactionId,
        public string $userId,
        public string $conversationId,
        public string $mpesaReceiptNumber,
        public int $amountKesCents,
        public DateTimeImmutable $disbursedAt
    ) {}

    public function toArray(): array
    {
        return [
            'event_type' => 'MpesaDisbursementCompleted',
            'transaction_id' => (string) $this->transactionId,
            'user_id' => $this->userId,
            'conversation_id' => $this->conversationId,
            'mpesa_receipt_number' => $this->mpesaReceiptNumber,
            'amount_kes_cents' => $this->amountKesCents,
            'disbursed_at' => $this->disbursedAt->format(DATE_ATOM),
        ];
    }
}