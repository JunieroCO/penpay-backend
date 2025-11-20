<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Aggregate;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\Currency;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Wallet\Entity\LedgerEntry;
use PenPay\Domain\Wallet\Entity\LedgerSide;
use PenPay\Domain\Wallet\Event\DepositInitiated;
use PenPay\Domain\Wallet\Event\BalanceChanged;
use DateTimeImmutable;
use InvalidArgumentException;

final class LedgerAccount
{
    private string $userId;
    /** @var LedgerEntry[] */
    private array $entries = [];

    /** @var object[] */
    private array $recordedEvents = [];

    private function __construct(string $userId)
    {
        $this->userId = $userId;
    }

    public static function create(string $userId): self
    {
        return new self($userId);
    }

    public function recordDeposit(
        TransactionId $transactionId,
        Money $amountUsd,
        Money $amountKes,
        LockedRate $lockedRate
    ): void {
        if ($amountUsd->cents < 0 || $amountKes->cents < 0) {
            throw new InvalidArgumentException('Deposit amount cannot be negative');
        }

        $this->entries[] = new LedgerEntry(
            transactionId: $transactionId,
            accountId: $this->userId,
            side: LedgerSide::CREDIT,
            amountUsd: $amountUsd,
            amountKes: $amountKes,
            lockedRate: $lockedRate,
            occurredAt: new DateTimeImmutable()
        );

        $this->raise(new DepositInitiated(
            userId: $this->userId,
            transactionId: $transactionId,
            amountUsd: $amountUsd,
            amountKes: $amountKes,
            lockedRate: $lockedRate,
            initiatedAt: new DateTimeImmutable()
        ));

        $this->raise(new BalanceChanged(
            userId: $this->userId,
            newBalanceUsd: $this->getBalanceUsd(),
            newBalanceKes: $this->getBalanceKes(),
            reason: 'deposit_completed',
            changedAt: new DateTimeImmutable()
        ));
    }

    public function getBalanceUsd(): Money
    {
        $total = 0;
        foreach ($this->entries as $entry) {
            $total += $entry->getUsdCents();
        }
        return new Money($total, Currency::USD);
    }

    public function getBalanceKes(): Money
    {
        $total = 0;
        foreach ($this->entries as $entry) {
            $total += $entry->getKesCents();
        }
        return new Money($total, Currency::KES);
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    private function raise(object $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /** @return object[] */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }
}