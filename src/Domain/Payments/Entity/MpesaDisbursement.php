<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Entity;

use PenPay\Domain\Wallet\ValueObject\Money;

final class MpesaDisbursement
{
    public function __construct(
        private string $mpesaReceipt,
        private int $resultCode,
        private \DateTimeImmutable $executedAt,
        private Money $amountKes,
        private array $rawResponse = []
    ) {}

    public function mpesaReceipt(): string
    {
        return $this->mpesaReceipt;
    }

    public function resultCode(): int
    {
        return $this->resultCode;
    }

    public function executedAt(): \DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function amountKes(): Money
    {
        return $this->amountKes;
    }

    public function rawResponse(): array
    {
        return $this->rawResponse;
    }
}