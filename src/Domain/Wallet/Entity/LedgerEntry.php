<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Entity;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Wallet\ValueObject\Currency;
use DateTimeImmutable;
use InvalidArgumentException;

enum LedgerSide: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';
}

final readonly class LedgerEntry
{
    public function __construct(
        public TransactionId $transactionId,
        public string $accountId,                    // e.g. user_123 or penpay_float
        public LedgerSide $side,                     // DEBIT or CREDIT
        public Money $amountUsd,
        public Money $amountKes,
        public LockedRate $lockedRate,
        public DateTimeImmutable $occurredAt,
    ) {
        if ($amountUsd->currency !== Currency::USD || $amountKes->currency !== Currency::KES) {
            throw new InvalidArgumentException('LedgerEntry must use USD and KES pairs');
        }
    }

    public function isCredit(): bool
    {
        return $this->side === LedgerSide::CREDIT;
    }

    public function isDebit(): bool
    {
        return $this->side === LedgerSide::DEBIT;
    }

    public function getUsdCents(): int
    {
        return $this->isCredit() ? $this->amountUsd->cents : -$this->amountUsd->cents;
    }

    public function getKesCents(): int
    {
        return $this->isCredit() ? $this->amountKes->cents : -$this->amountKes->cents;
    }
}