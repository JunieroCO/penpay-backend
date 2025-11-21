<?php
declare(strict_types=1);

namespace PenPay\Domain\Auth\ValueObject;

use PenPay\Domain\Shared\Kernel\Ulid;

final class TokenFamily
{
    private function __construct(private readonly string $value) {}

    public static function generate(): self
    {
        // Ulid::generate() returns Ulid object → cast to string
        return new self((string) Ulid::generate());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
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