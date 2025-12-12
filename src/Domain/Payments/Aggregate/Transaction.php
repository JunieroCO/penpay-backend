<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Aggregate;

use InvalidArgumentException;
use DomainException;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Payments\Entity\MpesaRequest;
use PenPay\Domain\Payments\Entity\MpesaDisbursement;
use PenPay\Domain\Payments\Entity\DerivTransfer;
use PenPay\Domain\Payments\Event\TransactionCreated;
use PenPay\Domain\Payments\Event\MpesaCallbackReceived;
use PenPay\Domain\Payments\Event\MpesaDisbursementCompleted;
use PenPay\Domain\Payments\Event\TransactionCompleted;
use PenPay\Domain\Payments\Event\TransactionFailed;

/**
 * Transaction Aggregate Root
 * 
 * Represents a financial transaction (deposit or withdrawal) between M-Pesa and Deriv.
 * Enforces state machine transitions and business invariants.
 * 
 * DEPOSIT FLOW (M-Pesa → Deriv):
 * PENDING → PROCESSING → AWAITING_MPESA_CALLBACK → AWAITING_DERIV_CONFIRMATION → COMPLETED
 * Completion: When Deriv credit succeeds (user already paid via M-Pesa)
 * 
 * WITHDRAWAL FLOW (Deriv → M-Pesa):
 * PENDING → PROCESSING → AWAITING_DERIV_CONFIRMATION → AWAITING_MPESA_DISBURSEMENT → COMPLETED
 * Completion: When M-Pesa disbursement succeeds (money already left Deriv)
 * 
 * @author PenPay Engineering
 * @version 2.0
 */
final class Transaction
{
    private TransactionId $id;
    private string $userId;
    private TransactionType $type;
    private TransactionStatus $status;

    private Money $amountUsd;
    private Money $amountKes;
    private LockedRate $lockedRate;

    private IdempotencyKey $idempotencyKey;

    private ?MpesaRequest $mpesaRequest = null;
    private ?DerivTransfer $derivTransfer = null;
    private ?MpesaDisbursement $mpesaDisbursement = null;

    private ?string $userDerivLoginId = null;
    private ?string $withdrawalVerificationCode = null;
    
    private ?string $failureReason = null;
    private ?string $providerError = null;
    private int $retryCount = 0;

    /** @var object[] */
    private array $recordedEvents = [];

    private function __construct(
        TransactionId $id,
        string $userId,
        TransactionType $type,
        Money $amountUsd,
        Money $amountKes,
        LockedRate $lockedRate,
        IdempotencyKey $idempotencyKey
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->type = $type;
        $this->amountUsd = $amountUsd;
        $this->amountKes = $amountKes;
        $this->lockedRate = $lockedRate;
        $this->idempotencyKey = $idempotencyKey;

        $this->status = TransactionStatus::PENDING;

        $this->raise(new TransactionCreated(
            transactionId: $id,
            userId: $userId,
            type: $type->value,
            amountUsdCents: $amountUsd->cents,
            amountKesCents: $amountKes->cents,
            idempotencyKey: (string)$idempotencyKey,
            occurredAt: new \DateTimeImmutable()
        ));
    }

    // ===================================================================
    // FACTORY METHODS
    // ===================================================================

    /**
     * Create a new deposit transaction (M-Pesa → Deriv)
     * 
     * @throws InvalidArgumentException if amount not in USD
     * @throws InvalidArgumentException if amount below minimum
     */
    public static function initiateDeposit(
        TransactionId $id,
        string $userId,
        Money $amountUsd,
        LockedRate $lockedRate,
        IdempotencyKey $idempotencyKey,
        string $userDerivLoginId
    ): self {
        if (!$amountUsd->currency->isUsd()) {
            throw new InvalidArgumentException('Deposit amount must be in USD');
        }

        if ($amountUsd->cents < 200) { // $2.00 minimum
            throw new InvalidArgumentException('Deposit minimum is $2.00');
        }

        if ($amountUsd->cents > 100000) { // $1000.00 maximum
            throw new InvalidArgumentException('Deposit maximum is $1000.00');
        }

        $amountKes = $lockedRate->convert($amountUsd);

        $tx = new self(
            $id,
            $userId,
            TransactionType::DEPOSIT,
            $amountUsd,
            $amountKes,
            $lockedRate,
            $idempotencyKey
        );

        $tx->userDerivLoginId = $userDerivLoginId;

        return $tx;
    }

    /**
     * Create a new withdrawal transaction (Deriv → M-Pesa)
     * 
     * @throws InvalidArgumentException if amount not in USD
     * @throws InvalidArgumentException if amount below minimum
     */
    public static function initiateWithdrawal(
        TransactionId $id,
        string $userId,
        Money $amountUsd,
        LockedRate $lockedRate,
        IdempotencyKey $idempotencyKey,
        string $userDerivLoginId,
        string $withdrawalVerificationCode
    ): self {
        if (!$amountUsd->currency->isUsd()) {
            throw new InvalidArgumentException('Withdrawal amount must be in USD');
        }

        if ($amountUsd->cents < 500) { // $5.00 minimum
            throw new InvalidArgumentException('Withdrawal minimum is $5.00');
        }

        if ($amountUsd->cents > 100000) { // $1000.00 maximum
            throw new InvalidArgumentException('Withdrawal maximum is $1000.00');
        }

        if (!preg_match('/^[A-Z0-9]{6}$/', strtoupper($withdrawalVerificationCode))) {
            throw new InvalidArgumentException('Withdrawal verification code must be 6 alphanumeric characters');
        }

        $amountKes = $lockedRate->convert($amountUsd);

        $tx = new self(
            $id,
            $userId,
            TransactionType::WITHDRAWAL,
            $amountUsd,
            $amountKes,
            $lockedRate,
            $idempotencyKey
        );

        $tx->userDerivLoginId = $userDerivLoginId;
        $tx->withdrawalVerificationCode = strtoupper($withdrawalVerificationCode);

        return $tx;
    }

    // ===================================================================
    // STATE TRANSITIONS (DEPOSIT FLOW)
    // ===================================================================

    /**
     * Transition to PROCESSING after STK Push initiated
     * 
     * @throws DomainException if state transition invalid
     */
    public function markStkPushInitiated(): void
    {
        if ($this->type !== TransactionType::DEPOSIT) {
            throw new DomainException('Only deposits have STK Push');
        }

        $newStatus = TransactionStatus::PROCESSING;
        $this->status->assertCanTransitionTo($newStatus);
        $this->status = $newStatus;
    }

    /**
     * Transition to AWAITING_MPESA_CALLBACK after STK sent to phone
     * 
     * @throws DomainException if state transition invalid
     */
    public function markAwaitingMpesaCallback(): void
    {
        if ($this->type !== TransactionType::DEPOSIT) {
            throw new DomainException('Only deposits await M-Pesa callback');
        }

        $newStatus = TransactionStatus::AWAITING_MPESA_CALLBACK;
        $this->status->assertCanTransitionTo($newStatus);
        $this->status = $newStatus;
    }

    /**
     * Record M-Pesa callback and transition to AWAITING_DERIV_CONFIRMATION
     * 
     * @throws DomainException if state transition invalid
     * @throws DomainException if not a deposit
     */
    public function recordMpesaCallback(MpesaRequest $request): void
    {
        if ($this->type !== TransactionType::DEPOSIT) {
            throw new DomainException('Only deposits have M-Pesa callbacks');
        }

        $newStatus = TransactionStatus::AWAITING_DERIV_CONFIRMATION;
        $this->status->assertCanTransitionTo($newStatus);

        $this->mpesaRequest = $request;
        $this->status = $newStatus;

        $this->raise(new MpesaCallbackReceived(
            transactionId: $this->id,
            userId: $this->userId,
            mpesaReceiptNumber: $request->mpesaReceiptNumber ?? '',
            phoneNumber: $request->phoneNumber->toE164(),
            amountKesCents: $request->amountKes->cents,
            callbackAt: new \DateTimeImmutable()
        ));
    }

    // ===================================================================
    // STATE TRANSITIONS (WITHDRAWAL FLOW)
    // ===================================================================

    /**
     * Transition to PROCESSING after Deriv withdrawal initiated
     * 
     * @throws DomainException if state transition invalid
     */
    public function markDerivWithdrawalInitiated(): void
    {
        if ($this->type !== TransactionType::WITHDRAWAL) {
            throw new DomainException('Only withdrawals have Deriv withdrawal');
        }

        $newStatus = TransactionStatus::PROCESSING;
        $this->status->assertCanTransitionTo($newStatus);
        $this->status = $newStatus;
    }

    /**
     * Transition to AWAITING_DERIV_CONFIRMATION after withdrawal requested
     * 
     * @throws DomainException if state transition invalid
     */
    public function markAwaitingDerivConfirmation(): void
    {
        $newStatus = TransactionStatus::AWAITING_DERIV_CONFIRMATION;
        $this->status->assertCanTransitionTo($newStatus);
        $this->status = $newStatus;
    }

    // ===================================================================
    // DERIV TRANSFER PROCESSING
    // ===================================================================

    /**
     * Record Deriv transfer - different completion logic for deposit vs withdrawal
     * 
     * @throws DomainException if state transition invalid
     * @throws DomainException if preconditions not met
     */
    public function recordDerivTransfer(DerivTransfer $transfer): void
    {
        // Validate transfer matches transaction
        if (!$transfer->transactionId->equals($this->id)) {
            throw new DomainException('Deriv transfer does not belong to this transaction');
        }

        // Type-specific validations
        if ($this->type === TransactionType::DEPOSIT) {
            if (!$this->hasMpesaCallback()) {
                throw new DomainException('Deposit requires M-Pesa callback before Deriv transfer');
            }
            if (!$transfer->isDeposit()) {
                throw new DomainException('Deposit transaction requires deposit-type Deriv transfer');
            }
        } else {
            if (!$transfer->isWithdrawal()) {
                throw new DomainException('Withdrawal transaction requires withdrawal-type Deriv transfer');
            }
            if ($transfer->withdrawalVerificationCode !== $this->withdrawalVerificationCode) {
                throw new DomainException('Withdrawal verification code mismatch');
            }
        }

        $this->derivTransfer = $transfer;

        if ($this->type === TransactionType::DEPOSIT) {
            // DEPOSIT: Complete when Deriv credit succeeds
            $newStatus = TransactionStatus::COMPLETED;
            $this->status->assertCanTransitionTo($newStatus);
            $this->status = $newStatus;

            $this->raise(new TransactionCompleted(
                transactionId: $this->id,
                userId: $this->userId,
                derivTransferId: $transfer->derivTransferId,
                derivTxnId: $transfer->derivTxnId,
                completedAt: new \DateTimeImmutable()
            ));
        } else {
            // WITHDRAWAL: Go to AWAITING_MPESA_DISBURSEMENT after Deriv debit
            $newStatus = TransactionStatus::AWAITING_MPESA_DISBURSEMENT;
            $this->status->assertCanTransitionTo($newStatus);
            $this->status = $newStatus;
        }
    }

    // ===================================================================
    // M-PESA DISBURSEMENT (WITHDRAWAL ONLY)
    // ===================================================================

    /**
     * Record M-Pesa disbursement for withdrawal - completes the transaction
     * 
     * @throws DomainException if not a withdrawal
     * @throws DomainException if not in correct state
     */
    public function recordMpesaDisbursement(MpesaDisbursement $disbursement): void
    {
        if ($this->type !== TransactionType::WITHDRAWAL) {
            throw new DomainException('Only withdrawals have M-Pesa disbursements');
        }

        if (!$this->status->isAwaitingMpesaDisbursement()) {
            throw new DomainException('Can only record disbursement to withdrawal awaiting M-Pesa');
        }

        if (!$this->hasDerivTransfer()) {
            throw new DomainException('Withdrawal requires Deriv transfer before M-Pesa disbursement');
        }

        // Idempotency: Don't record twice
        if ($this->mpesaDisbursement !== null) {
            return;
        }

        $this->mpesaDisbursement = $disbursement;

        if ($disbursement->isSuccessful()) {
            // WITHDRAWAL: Complete when M-Pesa disbursement succeeds
            $newStatus = TransactionStatus::COMPLETED;
            $this->status->assertCanTransitionTo($newStatus);
            $this->status = $newStatus;

            $this->raise(new MpesaDisbursementCompleted(
                transactionId: $this->id,
                userId: $this->userId,
                conversationId: $disbursement->conversationId,
                mpesaReceiptNumber: $disbursement->mpesaReceiptNumber,
                amountKesCents: $disbursement->amountKes->cents,
                disbursedAt: $disbursement->completedAt ?? new \DateTimeImmutable()
            ));
            
            // Also raise TransactionCompleted event
            $this->raise(new TransactionCompleted(
                transactionId: $this->id,
                userId: $this->userId,
                derivTransferId: $this->derivTransfer->derivTransferId,
                derivTxnId: $this->derivTransfer->derivTxnId,
                completedAt: new \DateTimeImmutable()
            ));
        }
        // If disbursement fails, transaction stays in AWAITING_MPESA_DISBURSEMENT
        // and can be retried or failed manually
    }

    // ===================================================================
    // FAILURE & REVERSAL
    // ===================================================================

    /**
     * Mark transaction as failed
     * 
     * @throws DomainException if already in terminal state
     */
    public function fail(string $reason, ?string $providerError = null): void
    {
        if ($this->status->isTerminal()) {
            throw new DomainException('Cannot fail transaction in terminal state');
        }

        $this->status = TransactionStatus::FAILED;
        $this->failureReason = $reason;
        $this->providerError = $providerError;

        $this->raise(new TransactionFailed(
            transactionId: $this->id,
            userId: $this->userId,
            reason: $reason,
            providerError: $providerError,
            failedAt: new \DateTimeImmutable()
        ));
    }

    /**
     * Reverse a completed transaction (admin only)
     * 
     * @throws DomainException if not completed
     */
    public function reverse(string $reason): void
    {
        if (!$this->status->isCompleted()) {
            throw new DomainException('Only completed transactions can be reversed');
        }

        $this->status->assertCanTransitionTo(TransactionStatus::REVERSED);
        $this->status = TransactionStatus::REVERSED;
        $this->failureReason = $reason;
    }

    // ===================================================================
    // RETRY LOGIC
    // ===================================================================

    /**
     * Increment retry counter
     */
    public function incrementRetryCount(): void
    {
        $this->retryCount++;
    }

    /**
     * Check if transaction can be retried
     */
    public function canRetry(int $maxRetries = 3): bool
    {
        return $this->retryCount < $maxRetries && !$this->isFinalized();
    }

    // ===================================================================
    // GUARDS & QUERIES
    // ===================================================================

    /**
     * Check if transaction is in terminal state
     */
    public function isFinalized(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Check if M-Pesa callback has been received
     */
    public function hasMpesaCallback(): bool
    {
        return $this->mpesaRequest !== null && $this->mpesaRequest->isCallbackReceived();
    }

    /**
     * Check if Deriv transfer has been executed
     */
    public function hasDerivTransfer(): bool
    {
        return $this->derivTransfer !== null;
    }

    /**
     * Check if M-Pesa disbursement has been recorded
     */
    public function hasMpesaDisbursement(): bool
    {
        return $this->mpesaDisbursement !== null;
    }

    /**
     * Check if transaction has Deriv login ID
     */
    public function hasDerivLoginId(): bool
    {
        return $this->userDerivLoginId !== null;
    }

    /**
     * Check if this transaction matches the idempotency key
     */
    public function hasIdempotencyKey(IdempotencyKey $key): bool
    {
        return $this->idempotencyKey->equals($key);
    }

    // ===================================================================
    // GETTERS
    // ===================================================================

    public function id(): TransactionId { return $this->id; }
    public function userId(): string { return $this->userId; }
    public function type(): TransactionType { return $this->type; }
    public function status(): TransactionStatus { return $this->status; }
    public function amountUsd(): Money { return $this->amountUsd; }
    public function amountKes(): Money { return $this->amountKes; }
    public function lockedRate(): LockedRate { return $this->lockedRate; }
    public function idempotencyKey(): IdempotencyKey { return $this->idempotencyKey; }
    public function userDerivLoginId(): ?string { return $this->userDerivLoginId; }
    public function mpesaRequest(): ?MpesaRequest { return $this->mpesaRequest; }
    public function derivTransfer(): ?DerivTransfer { return $this->derivTransfer; }
    public function mpesaDisbursement(): ?MpesaDisbursement { return $this->mpesaDisbursement; }
    public function failureReason(): ?string { return $this->failureReason; }
    public function providerError(): ?string { return $this->providerError; }
    public function retryCount(): int { return $this->retryCount; }
    public function withdrawalVerificationCode(): ?string { return $this->withdrawalVerificationCode; }

    // ===================================================================
    // DOMAIN EVENTS
    // ===================================================================

    /**
     * Release and clear all recorded domain events
     * 
     * @return object[]
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }

    /**
     * Record a domain event
     */
    private function raise(object $event): void
    {
        $this->recordedEvents[] = $event;
    }
}