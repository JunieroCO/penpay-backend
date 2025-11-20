<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Entity;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use DateTimeImmutable;

final readonly class DerivTransfer
{
    public function __construct(
        public TransactionId $transactionId,
        public string $derivAccountId,        // Deriv login ID
        public Money $amountUsd,
        public string $derivTransferId,       // From Deriv API response
        public string $derivTxnId,            // Deriv transaction ID
        public DateTimeImmutable $executedAt,
    ) {}
}