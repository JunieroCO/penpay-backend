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
        public string $phoneNumber,
        public Money $amountKes,
        public string $merchantRequestId,
        public string $checkoutRequestId,
        public ?string $mpesaReceiptNumber = null,
        public ?DateTimeImmutable $callbackReceivedAt = null,
        public DateTimeImmutable $initiatedAt = new DateTimeImmutable(),
    ) {
        if (!preg_match('/^2547[0-9]{8}$/', $phoneNumber)) {
            throw new InvalidArgumentException("Invalid Kenyan phone number: {$phoneNumber}");
        }
        if ($amountKes->currency !== Currency::KES) {
            throw new InvalidArgumentException('M-Pesa amount must be in KES');
        }
    }

    public static function initiated(
        TransactionId $transactionId,
        string $checkoutRequestId,
        string $phoneNumber,
        int $amountKesCents,
        ?string $merchantRequestId = null
    ): self {
        return new self(
            transactionId: $transactionId,
            phoneNumber: $phoneNumber,
            amountKes: Money::kes($amountKesCents),
            merchantRequestId: $merchantRequestId ?? 'merchant-' . uniqid(),
            checkoutRequestId: $checkoutRequestId,
            mpesaReceiptNumber: null,
            callbackReceivedAt: null,
            initiatedAt: new DateTimeImmutable()
        );
    }

    public static function fromCallback(
        string $checkoutRequestId,
        ?string $mpesaReceiptNumber,
        string $phoneNumber,
        int $amountKesCents,
        DateTimeImmutable $callbackReceivedAt,
        ?string $merchantRequestId = null
    ): self {
        return new self(
            transactionId: TransactionId::fromString('tmp'), 
            phoneNumber: $phoneNumber,
            amountKes: Money::kes($amountKesCents),
            merchantRequestId: $merchantRequestId ?? 'merchant-callback-' . uniqid(),
            checkoutRequestId: $checkoutRequestId,
            mpesaReceiptNumber: $mpesaReceiptNumber,
            callbackReceivedAt: $callbackReceivedAt,
            initiatedAt: new DateTimeImmutable()
        );
    }

    public function isCallbackReceived(): bool
    {
        return $this->mpesaReceiptNumber !== null;
    }
}