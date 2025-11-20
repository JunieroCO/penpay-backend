<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\ValueObject;

enum TransactionStatus: string
{
    case PENDING = 'pending';
    case MPESA_CONFIRMED = 'mpesa_confirmed';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case REVERSED = 'reversed';

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isMpesaConfirmed(): bool
    {
        return $this === self::MPESA_CONFIRMED;
    }

    public function isProcessing(): bool
    {
        return $this === self::PROCESSING;
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

    public function isTerminal(): bool
    {
        return $this->isCompleted() || $this->isFailed() || $this->isReversed();
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::PENDING         => $next === self::MPESA_CONFIRMED || $next === self::FAILED,
            self::MPESA_CONFIRMED => $next === self::PROCESSING || $next === self::FAILED,
            self::PROCESSING      => $next === self::COMPLETED || $next === self::FAILED,
            self::COMPLETED       => $next === self::REVERSED,
            self::FAILED          => false,
            self::REVERSED        => false,
        };
    }
}