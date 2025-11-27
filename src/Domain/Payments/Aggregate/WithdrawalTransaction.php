<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Aggregate;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Payments\Entity\DerivWithdrawal;
use PenPay\Domain\Payments\Entity\MpesaDisbursement;
use PenPay\Domain\Payments\Event\TransactionCreated;
use PenPay\Domain\Payments\Event\TransactionCompleted;
use PenPay\Domain\Payments\Event\TransactionFailed;
use InvalidArgumentException;
use LogicException;

final class WithdrawalTransaction
{
    private TransactionId $id;
    private readonly string $userId;
    private readonly TransactionType $type;
    private readonly Money $amountUsd;
    private ?Money $amountKes = null;
    private ?float $exchangeRate = null;
    private TransactionStatus $status;
    private readonly IdempotencyKey $idempotencyKey;

    private ?DerivWithdrawal $derivWithdrawal = null;
    private ?MpesaDisbursement $mpesaDisbursement = null;

    /** @var object[] */
    private array $recordedEvents = [];

    private function __construct(
        TransactionId $id,
        string $userId,
        Money $amountUsd,
        IdempotencyKey $idempotencyKey
    ) {
        if (!$amountUsd->currency->isUsd()) {
            throw new InvalidArgumentException('Withdrawal amount must be in USD');
        }

        $this->id = $id;
        $this->userId = $userId;
        $this->type = TransactionType::WITHDRAWAL;
        $this->amountUsd = $amountUsd;
        $this->idempotencyKey = $idempotencyKey;
        $this->status = TransactionStatus::PENDING;
    }

    public static function initiate(
        TransactionId $id,
        string $userId,
        Money $amountUsd,
        IdempotencyKey $idempotencyKey,
        ?float $lockedExchangeRate = null
    ): self {
        $tx = new self($id, $userId, $amountUsd, $idempotencyKey);

        if ($lockedExchangeRate !== null) {
            $tx->exchangeRate = $lockedExchangeRate;
        }

        $tx->raise(new TransactionCreated(
            transactionId: $tx->id,
            userId: $userId,
            type: $tx->type->value,
            amount: $amountUsd,
            idempotencyKey: $idempotencyKey,
            occurredAt: new \DateTimeImmutable()
        ));

        return $tx;
    }

    // ========== DOMAIN BEHAVIOR ==========

    private function ensurePending(): void
    {
        if (!$this->status->isPending()) {
            throw new LogicException("Transaction {$this->id} is not pending (current: {$this->status->value})");
        }
    }

    public function recordDerivDebit(
        string $derivTransferId,
        string $derivTxnId,
        \DateTimeImmutable $executedAt,
        array $rawResponse = []
    ): void {
        $this->ensurePending();

        if ($this->derivWithdrawal !== null) {
            return; 
        }

        $this->derivWithdrawal = new DerivWithdrawal($derivTransferId, $derivTxnId, $executedAt, $rawResponse);
        // Status remains PENDING — completion only after M-Pesa
    }

    public function recordMpesaDisbursement(
        string $mpesaReceipt,
        int $resultCode,
        \DateTimeImmutable $executedAt,
        Money $amountKes,
        array $rawResponse = []
    ): void {
        $this->ensurePending();

        if ($this->mpesaDisbursement !== null) {
            return; 
        }

        if (!$amountKes->currency->isKes()) {
            throw new InvalidArgumentException('M-Pesa disbursement must be in KES');
        }

        $this->mpesaDisbursement = new MpesaDisbursement(
            $mpesaReceipt,
            $resultCode,
            $executedAt,
            $amountKes,
            $rawResponse
        );

        $this->amountKes = $amountKes;
        $this->status = TransactionStatus::COMPLETED;

        $this->raise(new TransactionCompleted(
            transactionId: $this->id,
            derivTransferId: $this->derivWithdrawal?->derivTransferId() ?? '',
            derivTxnId: $this->derivWithdrawal?->derivTxnId() ?? '',
            completedAt: $executedAt
        ));
    }

    public function fail(string $reason, ?string $providerError = null): void
    {
        if ($this->status->isTerminal()) {  
            return;
        }

        $this->status = TransactionStatus::FAILED;

        $this->raise(new TransactionFailed(
            transactionId: $this->id,
            reason: $reason,
            providerError: $providerError,
            failedAt: new \DateTimeImmutable()
        ));
    }

    // ========== QUERIES ==========

    public function isFinalized(): bool
    {
        return $this->status->isCompleted() || $this->status->isFailed();
    }

    public function hasDerivDebit(): bool
    {
        return $this->derivWithdrawal !== null;
    }

    public function hasMpesaDisbursement(): bool
    {
        return $this->mpesaDisbursement !== null;
    }

    // ========== GETTERS ==========

    public function id(): TransactionId { return $this->id; }
    public function userId(): string { return $this->userId; }
    public function type(): TransactionType { return $this->type; }
    public function status(): TransactionStatus { return $this->status; }
    public function amountUsd(): Money { return $this->amountUsd; }
    public function amountKes(): ?Money { return $this->amountKes; }
    public function exchangeRate(): ?float { return $this->exchangeRate; }
    public function idempotencyKey(): IdempotencyKey { return $this->idempotencyKey; }
    public function derivWithdrawal(): ?DerivWithdrawal { return $this->derivWithdrawal; }
    public function mpesaDisbursement(): ?MpesaDisbursement { return $this->mpesaDisbursement; }

    // ========== EVENT SOURCING ==========

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