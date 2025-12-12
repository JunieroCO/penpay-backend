<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Entity;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MpesaRequest
{
    public function __construct(
        public TransactionId $transactionId,
        public PhoneNumber $phoneNumber,
        public Money $amountKes,
        public string $merchantRequestId,
        public string $checkoutRequestId,
        public ?string $mpesaReceiptNumber = null,
        public ?DateTimeImmutable $callbackReceivedAt = null,
        public DateTimeImmutable $initiatedAt = new DateTimeImmutable(),
        public array $rawPayload = [],
    ) {
        if (!$amountKes->currency->isKes()) {
            throw new InvalidArgumentException('M-Pesa request amount must be in KES.');
        }
    }

    public static function initiated(
        TransactionId $transactionId,
        string $checkoutRequestId,
        PhoneNumber $phoneNumber,
        Money $amountKes,
        ?string $merchantRequestId = null,
        array $rawPayload = [],
    ): self {
        return new self(
            transactionId: $transactionId,
            phoneNumber: $phoneNumber,
            amountKes: $amountKes,
            merchantRequestId: $merchantRequestId ?? 'merchant-' . uniqid(),
            checkoutRequestId: $checkoutRequestId,
            mpesaReceiptNumber: null,
            callbackReceivedAt: null,
            initiatedAt: new DateTimeImmutable(),
            rawPayload: $rawPayload
        );
    }

    public static function fromCallback(
        string $checkoutRequestId,
        ?string $mpesaReceiptNumber,
        PhoneNumber $phoneNumber,
        Money $amountKes,
        DateTimeImmutable $callbackReceivedAt,
        ?string $merchantRequestId = null,
        array $rawPayload = [],
    ): self {
        return new self(
            transactionId: TransactionId::fromString('00000000-0000-7000-8000-000000000000'),
            phoneNumber: $phoneNumber,
            amountKes: $amountKes,
            merchantRequestId: $merchantRequestId ?? 'merchant-callback-' . uniqid(),
            checkoutRequestId: $checkoutRequestId,
            mpesaReceiptNumber: $mpesaReceiptNumber,
            callbackReceivedAt: $callbackReceivedAt,
            initiatedAt: new DateTimeImmutable(),
            rawPayload: $rawPayload
        );
    }

    public function isCallbackReceived(): bool
    {
        return $this->mpesaReceiptNumber !== null;
    }
}