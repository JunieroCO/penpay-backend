<?php
declare(strict_types=1);

namespace PenPay\Domain\Auth\ValueObject;

use PenPay\Domain\Shared\Kernel\Ulid;

final readonly class RefreshTokenId
{
    private function __construct(private Ulid $ulid) {}

    public static function generate(): self
    {
        return new self(Ulid::generate());
    }

    public static function fromString(string $value): self
    {
        return new self(Ulid::fromString($value));
    }

    public function toString(): string
    {
        return $this->ulid->toString();
    }

    public function equals(self $other): bool
    {
        return $this->ulid->equals($other->ulid);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}