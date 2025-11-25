<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Aggregate;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\Currency;
use PenPay\Domain\Payments\Entity\MpesaRequest;
use PenPay\Domain\Payments\Entity\DerivTransfer;
use PenPay\Domain\Payments\Event\TransactionCreated;
use PenPay\Domain\Payments\Event\MpesaCallbackReceived;
use PenPay\Domain\Payments\Event\TransactionCompleted;
use PenPay\Domain\Payments\Event\TransactionFailed;
use InvalidArgumentException;

final class Transaction
{
    private TransactionId $id;
    private TransactionType $type;
    private Money $amount;
    private IdempotencyKey $idempotencyKey;
    private TransactionStatus $status;

    private ?MpesaRequest $mpesaRequest = null;
    private ?DerivTransfer $derivTransfer = null;

    // Deriv settlement data — set once, early
    private ?string $userDerivLoginId = null;
    private ?string $paymentAgentToken = null;
    private ?float $amountUsd = null;

    /** @var object[] */
    private array $recordedEvents = [];

    private function __construct(
        TransactionId $id,
        TransactionType $type,
        Money $amount,
        IdempotencyKey $idempotencyKey
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->amount = $amount;
        $this->idempotencyKey = $idempotencyKey;
        $this->status = TransactionStatus::PENDING;

        $this->raise(new TransactionCreated(
            transactionId: $id,
            userId: '', // Filled by service layer
            type: $type->value,
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            occurredAt: new \DateTimeImmutable()
        ));
    }

    // === FACTORY METHODS ===
    public static function initiateDeposit(
        TransactionId $id,
        Money $amountKes,
        IdempotencyKey $idempotencyKey,
        ?string $userDerivLoginId = null,
        ?string $paymentAgentToken = null,
        ?float $amountUsd = null
    ): self {
        if ($amountKes->currency !== Currency::KES) {
            throw new InvalidArgumentException('Deposit must be in KES');
        }

        $tx = new self($id, TransactionType::DEPOSIT, $amountKes, $idempotencyKey);
        $tx->userDerivLoginId = $userDerivLoginId;
        $tx->paymentAgentToken = $paymentAgentToken;
        $tx->amountUsd = $amountUsd;

        return $tx;
    }

    public static function initiateWithdrawal(
        TransactionId $id,
        Money $amountUsd,
        IdempotencyKey $idempotencyKey
    ): self {
        if ($amountUsd->currency !== Currency::USD) {
            throw new InvalidArgumentException('Withdrawal must be in USD');
        }

        return new self($id, TransactionType::WITHDRAWAL, $amountUsd, $idempotencyKey);
    }

    // === DOMAIN BEHAVIOR ===
    public function recordMpesaCallback(MpesaRequest $request): void
    {
        if (!$this->status->isPending()) {
            throw new InvalidArgumentException('Cannot record callback on non-pending transaction');
        }
        if ($this->type !== TransactionType::DEPOSIT) {
            throw new InvalidArgumentException('Only deposits have M-Pesa callbacks');
        }

        $this->mpesaRequest = $request;
        $this->status = TransactionStatus::MPESA_CONFIRMED;

        $this->raise(new MpesaCallbackReceived(
            transactionId: $this->id,
            mpesaReceiptNumber: $request->mpesaReceiptNumber ?? '',
            phoneNumber: $request->phoneNumber,
            amountKesCents: $request->amountKes->cents,
            callbackAt: $request->callbackReceivedAt ?? new \DateTimeImmutable()
        ));
    }

    public function completeWithDerivTransfer(DerivTransfer $transfer): void
    {
        if ($this->type === TransactionType::DEPOSIT && !$this->status->isMpesaConfirmed()) {
            throw new InvalidArgumentException('M-Pesa callback required before completing deposit');
        }

        $this->derivTransfer = $transfer;
        $this->status = TransactionStatus::COMPLETED;

        $this->raise(new TransactionCompleted(
            transactionId: $this->id,
            derivTransferId: $transfer->derivTransferId,
            derivTxnId: $transfer->derivTxnId,
            completedAt: new \DateTimeImmutable()
        ));
    }

    public function fail(string $reason, ?string $providerError = null): void
    {
        $this->status = TransactionStatus::FAILED;

        $this->raise(new TransactionFailed(
            transactionId: $this->id,
            reason: $reason,
            providerError: $providerError,
            failedAt: new \DateTimeImmutable()
        ));
    }

    // === GUARDS & QUERIES (for DerivTransferWorker) ===
    public function hasMpesaCallback(): bool
    {
        return $this->mpesaRequest !== null;
    }

    public function hasDerivCredentials(): bool
    {
        return $this->userDerivLoginId !== null && $this->paymentAgentToken !== null;
    }

    public function hasUsdAmount(): bool
    {
        return $this->amountUsd !== null && $this->amountUsd > 0;
    }

    public function isFinalized(): bool
    {
        return $this->status->isCompleted() || $this->status->isFailed();
    }

    public function userDerivLoginId(): ?string
    {
        return $this->userDerivLoginId;
    }

    public function paymentAgentToken(): ?string
    {
        return $this->paymentAgentToken;
    }

    public function amountUsd(): ?float
    {
        return $this->amountUsd;
    }

    public function mpesaReceiptNumber(): ?string
    {
        return $this->mpesaRequest?->mpesaReceiptNumber;
    }

    // === STANDARD GETTERS ===
    public function getId(): TransactionId { return $this->id; }
    public function getAmount(): Money { return $this->amount; }
    public function getStatus(): TransactionStatus { return $this->status; }
    public function getType(): TransactionType { return $this->type; }

    // === EVENT SOURCING ===
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