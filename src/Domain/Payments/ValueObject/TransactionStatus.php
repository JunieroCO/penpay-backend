<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\ValueObject;

use InvalidArgumentException;

enum TransactionStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case AWAITING_MPESA_CALLBACK = 'AWAITING_MPESA_CALLBACK';
    case AWAITING_DERIV_CONFIRMATION = 'AWAITING_DERIV_CONFIRMATION';
    case AWAITING_MPESA_DISBURSEMENT = 'AWAITING_MPESA_DISBURSEMENT';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case REVERSED = 'REVERSED';

    /**
     * Check if transition to new status is valid
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::PENDING => in_array($next, [
                self::PROCESSING,
                self::FAILED
            ], true),
            
            self::PROCESSING => in_array($next, [
                self::AWAITING_MPESA_CALLBACK,      // Deposit: after STK push
                self::AWAITING_DERIV_CONFIRMATION,  // Withdrawal: after initiating
                self::COMPLETED,                    // Direct completion (if needed)
                self::FAILED
            ], true),
            
            self::AWAITING_MPESA_CALLBACK => in_array($next, [
                self::AWAITING_DERIV_CONFIRMATION,  // Deposit: after M-Pesa callback
                self::FAILED
            ], true),
            
            self::AWAITING_DERIV_CONFIRMATION => in_array($next, [
                self::PROCESSING,                    // Retry Deriv
                self::AWAITING_MPESA_DISBURSEMENT,   // Withdrawal: after Deriv debit success
                self::COMPLETED,                     // Deposit: after Deriv credit success
                self::FAILED                         // Deriv failure
            ], true),
            
            self::AWAITING_MPESA_DISBURSEMENT => in_array($next, [
                self::PROCESSING,                    // Retry M-Pesa
                self::COMPLETED,                     // Withdrawal: after M-Pesa success
                self::FAILED                         // M-Pesa failure (after retries)
            ], true),
            
            self::COMPLETED => $next === self::REVERSED,
            
            self::FAILED => false,    
            self::REVERSED => false,  
        };
    }

    /**
     * Assert that transition is valid, throw exception if not
     * 
     * @throws InvalidArgumentException if transition invalid
     */
    public function assertCanTransitionTo(self $next): void
    {
        if (!$this->canTransitionTo($next)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid state transition: %s → %s',
                    $this->value,
                    $next->value
                )
            );
        }
    }

    // ===================================================================
    // STATE CHECKS
    // ===================================================================

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isProcessing(): bool
    {
        return $this === self::PROCESSING;
    }

    public function isAwaitingMpesaCallback(): bool
    {
        return $this === self::AWAITING_MPESA_CALLBACK;
    }

    public function isAwaitingDerivConfirmation(): bool
    {
        return $this === self::AWAITING_DERIV_CONFIRMATION;
    }

    public function isAwaitingMpesaDisbursement(): bool
    {
        return $this === self::AWAITING_MPESA_DISBURSEMENT;
    }

    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    public function isReversed(): bool
    {
        return $this === self::REVERSED;
    }

    /**
     * Check if transaction is in terminal state (cannot transition further)
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::FAILED,
            self::REVERSED
        ], true);
    }

    /**
     * Check if transaction can proceed to next operation
     */
    public function canProceed(): bool
    {
        return !$this->isTerminal();
    }

    /**
     * Get human-readable description
     */
    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Pending initiation',
            self::PROCESSING => 'Processing transaction',
            self::AWAITING_MPESA_CALLBACK => 'Waiting for M-Pesa confirmation',
            self::AWAITING_DERIV_CONFIRMATION => 'Waiting for Deriv confirmation',
            self::AWAITING_MPESA_DISBURSEMENT => 'Waiting for M-Pesa disbursement',
            self::COMPLETED => 'Transaction completed successfully',
            self::FAILED => 'Transaction failed',
            self::REVERSED => 'Transaction reversed',
        };
    }
}