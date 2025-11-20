<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Aggregate;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
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

enum TransactionType: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
}

final class Transaction
{
    private TransactionId $id;
    private TransactionType $type;
    private Money $amount;
    private IdempotencyKey $idempotencyKey;
    private TransactionStatus $status;
    private ?MpesaRequest $mpesaRequest = null;
    private ?DerivTransfer $derivTransfer = null;

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
            userId: '', // Will be filled by service layer
            type: $type->value,
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            occurredAt: new \DateTimeImmutable()
        ));
    }

    public static function initiateDeposit(
        TransactionId $id,
        Money $amountKes,
        IdempotencyKey $idempotencyKey
    ): self {
        if ($amountKes->currency !== Currency::KES) {
            throw new InvalidArgumentException('Deposit must be in KES');
        }
        return new self($id, TransactionType::DEPOSIT, $amountKes, $idempotencyKey);
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
            throw new InvalidArgumentException('M-Pesa callback required before Deriv transfer');
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

    public function fail(string $reason): void
    {
        $this->status = TransactionStatus::FAILED;
        $this->raise(new TransactionFailed(
            transactionId: $this->id,
            reason: $reason,
            providerError: null,
            failedAt: new \DateTimeImmutable()
        ));
    }

    // Getters
    public function getId(): TransactionId { return $this->id; }
    public function getAmount(): Money { return $this->amount; }
    public function getStatus(): TransactionStatus { return $this->status; }
    public function getType(): TransactionType { return $this->type; }

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