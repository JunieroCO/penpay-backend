<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Event;

use PenPay\Domain\Shared\Kernel\TransactionId;
use DateTimeImmutable;

final readonly class MpesaCallbackReceived
{
    public function __construct(
        public TransactionId $transactionId,
        public string $mpesaReceiptNumber,
        public string $phoneNumber,
        public int $amountKesCents,
        public DateTimeImmutable $callbackAt
    ) {}
}