<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class IdempotencyKey
{
    private const MIN_LENGTH = 8;
    private const MAX_LENGTH = 128;

    public function __construct(
        public string $value,
        public DateTimeImmutable $expiresAt
    ) {
        if (strlen($value) < self::MIN_LENGTH || strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException("Idempotency key must be between 8 and 128 characters");
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException("Idempotency key contains invalid control characters");
        }
    }

    public static function generate(int $hoursValid = 24): self
    {
        $key = 'ik_' . bin2hex(random_bytes(32));
        return new self($key, new DateTimeImmutable("+{$hoursValid} hours"));
    }

    public static function fromHeader(string $header): self
    {
        return new self(trim($header), new DateTimeImmutable('+24 hours'));
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
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