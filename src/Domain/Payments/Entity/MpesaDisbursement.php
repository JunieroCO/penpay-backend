<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Entity;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MpesaDisbursement
{
    public function __construct(
        public TransactionId $transactionId,
        public PhoneNumber $phoneNumber,
        public Money $amountKes,
        public string $conversationId,
        public string $originatorConversationId,
        public ?string $mpesaReceiptNumber = null,
        public ?string $resultCode = null,
        public ?string $resultDescription = null,
        public ?DateTimeImmutable $callbackReceivedAt = null,
        public DateTimeImmutable $initiatedAt = new DateTimeImmutable(),
        public array $rawPayload = [],
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if (!$this->amountKes->currency->isKes()) {
            throw new InvalidArgumentException('M-Pesa disbursement amount must be in KES.');
        }
        if ($this->conversationId === '' || $this->originatorConversationId === '') {
            throw new InvalidArgumentException('Conversation IDs must not be empty.');
        }
    }


    public static function initiated(
        TransactionId $transactionId,
        string $conversationId,
        string $originatorConversationId,
        PhoneNumber $phoneNumber,
        Money $amountKes,
        array $rawPayload = [],
    ): self {
        return new self(
            transactionId: $transactionId,
            phoneNumber: $phoneNumber,
            amountKes: $amountKes,
            conversationId: $conversationId,
            originatorConversationId: $originatorConversationId,
            mpesaReceiptNumber: null,
            resultCode: null,
            resultDescription: null,
            callbackReceivedAt: null,
            initiatedAt: new DateTimeImmutable(),
            rawPayload: $rawPayload
        );
    }

    public static function fromCallback(
        string $conversationId,
        ?string $originatorConversationId,
        PhoneNumber $phoneNumber,
        Money $amountKes,
        ?string $mpesaReceiptNumber,
        ?string $resultCode,
        ?string $resultDescription,
        DateTimeImmutable $callbackReceivedAt,
        array $rawPayload = [],
    ): self {
        return new self(
            transactionId: TransactionId::fromString('00000000-0000-7000-8000-000000000000'), // Placeholder
            phoneNumber: $phoneNumber,
            amountKes: $amountKes,
            conversationId: $conversationId,
            originatorConversationId: $originatorConversationId ?? $conversationId,
            mpesaReceiptNumber: $mpesaReceiptNumber,
            resultCode: $resultCode,
            resultDescription: $resultDescription,
            callbackReceivedAt: $callbackReceivedAt,
            initiatedAt: $callbackReceivedAt,
            rawPayload: $rawPayload
        );
    }

    public static function fromArray(array $data): self
    {
        
        if (!isset($data['transaction_id'])) {
            throw new InvalidArgumentException('Transaction ID is required in fromArray()');
        }
        
        // Handle phone_number - can be string or PhoneNumber object
        $phoneNumber = $data['phone_number'];
        if (!$phoneNumber instanceof PhoneNumber) {
            $phoneNumber = PhoneNumber::fromKenyan($phoneNumber);
        }
        
        return new self(
            transactionId: TransactionId::fromString($data['transaction_id']),
            phoneNumber: $phoneNumber,
            amountKes: Money::kes((int)($data['amount_kes_cents'])),
            conversationId: $data['conversation_id'],
            originatorConversationId: $data['originator_conversation_id'],
            mpesaReceiptNumber: $data['mpesa_receipt_number'] ?? null,
            resultCode: $data['result_code'] ?? null,
            resultDescription: $data['result_description'] ?? null,
            callbackReceivedAt: isset($data['completed_at']) ? new DateTimeImmutable($data['completed_at']) : null,
            initiatedAt: new DateTimeImmutable(),
            rawPayload: $data['raw_payload'] ?? []
        );
    }

    public function isSuccessful(): bool
    {
        return $this->resultCode === '0';
    }
}