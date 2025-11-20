<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Event;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use DateTimeImmutable;

final readonly class WithdrawalInitiated
{
    public function __construct(
        public string $userId,
        public TransactionId $transactionId,
        public Money $amountUsd,
        public DateTimeImmutable $initiatedAt
    ) {}
}