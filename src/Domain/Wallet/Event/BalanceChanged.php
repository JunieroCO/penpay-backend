<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Event;

use PenPay\Domain\Wallet\ValueObject\Money;
use DateTimeImmutable;

final readonly class BalanceChanged
{
    public function __construct(
        public string $userId,
        public Money $newBalanceUsd,
        public Money $newBalanceKes,
        public string $reason, // "deposit_completed", "withdrawal", etc.
        public DateTimeImmutable $changedAt
    ) {}
}