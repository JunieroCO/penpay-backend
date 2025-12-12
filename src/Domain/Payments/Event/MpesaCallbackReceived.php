<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Event;

use PenPay\Domain\Shared\Kernel\TransactionId;
use DateTimeImmutable;

final readonly class MpesaCallbackReceived
{
    public function __construct(
        public TransactionId $transactionId,
        public string $userId,
        public string $mpesaReceiptNumber,
        public string $phoneNumber,
        public int $amountKesCents,
        public DateTimeImmutable $callbackAt
    ) {}

    public function toArray(): array
    {
        return [
            'event_type' => 'MpesaCallbackReceived',
            'transaction_id' => (string) $this->transactionId,
            'user_id' => $this->userId,
            'mpesa_receipt_number' => $this->mpesaReceiptNumber,
            'phone_number' => $this->phoneNumber,
            'amount_kes_cents' => $this->amountKesCents,
            'callback_at' => $this->callbackAt->format(DATE_ATOM),
        ];
    }
}