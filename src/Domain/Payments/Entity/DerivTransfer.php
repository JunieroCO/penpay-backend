<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Entity;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use DateTimeImmutable;

/**
 * Represents a successful Deriv Payment Agent deposit (USD credit)
 *
 * This is an immutable Value Object.
 * Created only when Deriv confirms the transfer succeeded.
 */
final readonly class DerivTransfer
{
    private function __construct(
        public TransactionId $transactionId,
        public string $derivAccountId,        // e.g. CR123456
        public Money $amountUsd,
        public string $derivTransferId,       // Deriv's internal transfer reference
        public string $derivTxnId,            // Deriv's transaction ID (visible to user)
        public DateTimeImmutable $executedAt,
        public ?array $rawResponse = null,    // Full API response for auditing
    ) {}

    /**
     * Factory for successful transfers
     */
    public static function success(
        TransactionId $transactionId,
        string $derivAccountId,
        Money $amountUsd,
        string $derivTransferId,
        string $derivTxnId,
        ?DateTimeImmutable $executedAt = null,
        ?array $rawResponse = null,
    ): self {
        return new self(
            transactionId: $transactionId,
            derivAccountId: $derivAccountId,
            amountUsd: $amountUsd,
            derivTransferId: $derivTransferId,
            derivTxnId: $derivTxnId,
            executedAt: $executedAt ?? new DateTimeImmutable(),
            rawResponse: $rawResponse,
        );
    }

    /**
     * Optional: for rehydration from EventStore / read models
     */
    public static function fromArray(array $data): self
    {
        return new self(
            transactionId: TransactionId::fromString($data['transaction_id']),
            derivAccountId: $data['deriv_account_id'],
            amountUsd: Money::usd((int) round($data['amount_usd_cents'])),
            derivTransferId: $data['deriv_transfer_id'],
            derivTxnId: $data['deriv_txn_id'],
            executedAt: new DateTimeImmutable($data['executed_at']),
            rawResponse: $data['raw_response'] ?? null,
        );
    }
}