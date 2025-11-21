<?php
declare(strict_types=1);

namespace PenPay\Domain\User\ValueObject;

use InvalidArgumentException;

final readonly class DerivLoginId
{
    private string $value;

    private function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Deriv login ID cannot be empty');
        }

        if (strlen($value) < 7 || strlen($value) > 20) {
            throw new InvalidArgumentException('Deriv login ID must be 7–20 characters');
        }

        if (!preg_match('/^(?:[1-9]\d{6,18}|[A-Z]{2,6}\d{7,18})$/', $value)) {
            throw new InvalidArgumentException('Invalid Deriv login ID format');
        }

        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function fromInt(int $value): self
    {
        return new self((string) $value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isVirtual(): bool
    {
        return str_starts_with($this->value, 'VRTC');
    }

    public function isReal(): bool
    {
        return preg_match('/^\d+$/', $this->value) === 1;
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