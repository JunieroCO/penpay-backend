<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Entity;

final class DerivWithdrawal
{
    public function __construct(
        private string $derivTransferId,
        private string $derivTxnId,
        private \DateTimeImmutable $executedAt,
        private array $rawResponse = []
    ) {}

    public function derivTransferId(): string
    {
        return $this->derivTransferId;
    }

    public function derivTxnId(): string
    {
        return $this->derivTxnId;
    }

    public function executedAt(): \DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function rawResponse(): array
    {
        return $this->rawResponse;
    }
}