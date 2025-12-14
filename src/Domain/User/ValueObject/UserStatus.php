<?php
declare(strict_types=1);

namespace PenPay\Domain\User\ValueObject;

use InvalidArgumentException;

final readonly class UserStatus
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_SUSPENDED = 'suspended';
    private const STATUS_BANNED = 'banned';
    private const STATUS_CLOSED = 'closed';

    private const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_BANNED,
        self::STATUS_CLOSED,
    ];

    private function __construct(
        public string $value
    ) {
        if (!in_array($value, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid user status: %s. Valid statuses: %s',
                    $value,
                    implode(', ', self::VALID_STATUSES)
                )
            );
        }
    }

    public static function pending(): self
    {
        return new self(self::STATUS_PENDING);
    }

    public static function active(): self
    {
        return new self(self::STATUS_ACTIVE);
    }

    public static function suspended(): self
    {
        return new self(self::STATUS_SUSPENDED);
    }

    public static function banned(): self
    {
        return new self(self::STATUS_BANNED);
    }

    public static function closed(): self
    {
        return new self(self::STATUS_CLOSED);
    }

    public static function fromString(string $status): self
    {
        return new self(strtolower(trim($status)));
    }

    public function isPending(): bool
    {
        return $this->value === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return $this->value === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->value === self::STATUS_SUSPENDED;
    }

    public function isBanned(): bool
    {
        return $this->value === self::STATUS_BANNED;
    }

    public function isClosed(): bool
    {
        return $this->value === self::STATUS_CLOSED;
    }

    /**
     * Check if user can perform transactions
     */
    public function canTransact(): bool
    {
        return $this->isActive();
    }

    /**
     * Check if status allows login
     */
    public function canLogin(): bool
    {
        return $this->isActive() || $this->isPending();
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this->value) {
            self::STATUS_PENDING => 'Pending Verification',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_SUSPENDED => 'Suspended',
            self::STATUS_BANNED => 'Banned',
            self::STATUS_CLOSED => 'Closed',
        };
    }

    /**
     * Get all valid status values
     * 
     * @return array<string>
     */
    public static function validStatuses(): array
    {
        return self::VALID_STATUSES;
    }
}