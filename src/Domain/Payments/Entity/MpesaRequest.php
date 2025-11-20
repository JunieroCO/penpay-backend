<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Entity;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\Currency;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MpesaRequest
{
    public function __construct(
        public TransactionId $transactionId,
        public string $phoneNumber,                    // e.g. "2547xxxxxxxx"
        public Money $amountKes,
        public string $merchantRequestId,              // From M-Pesa
        public string $checkoutRequestId,              // From M-Pesa STK Push
        public ?string $mpesaReceiptNumber = null,     // Filled on callback
        public ?DateTimeImmutable $callbackReceivedAt = null,
        public DateTimeImmutable $initiatedAt,
    ) {
        if (!preg_match('/^2547[0-9]{8}$/', $phoneNumber)) {
            throw new InvalidArgumentException("Invalid Kenyan phone number: {$phoneNumber}");
        }

        if ($amountKes->currency !== Currency::KES) {
            throw new InvalidArgumentException('M-Pesa amount must be in KES');
        }
    }

    public function isCallbackReceived(): bool
    {
        return $this->mpesaReceiptNumber !== null;
    }
}