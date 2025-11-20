<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\Kernel;

use Ramsey\Uuid\Uuid;
use InvalidArgumentException;

final readonly class TransactionId
{
    private function __construct(
        public string $value
    ) {
        // UUIDv7 string is 36 chars (8-4-4-4-12), but we validate format + version 7
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->value)) {
            throw new InvalidArgumentException("Invalid TransactionId (must be UUIDv7): {$this->value}");
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