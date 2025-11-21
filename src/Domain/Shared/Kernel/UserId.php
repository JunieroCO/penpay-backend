<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\Kernel;

use Ramsey\Uuid\Uuid;
use InvalidArgumentException;

final readonly class UserId
{
    private function __construct(
        public string $value
    ) {
        // Strict UUIDv7 format validation (compatible with ramsey/uuid ^4.2+)
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new InvalidArgumentException("Invalid UserId: must be a valid UUIDv7");
        }
    }

    public static function generate(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}