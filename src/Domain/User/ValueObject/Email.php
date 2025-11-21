<?php
declare(strict_types=1);

namespace PenPay\Domain\User\ValueObject;

use InvalidArgumentException;

final readonly class Email
{
    private string $value;

    private function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Email cannot be empty');
        }

        if (strlen($value) > 255) {
            throw new InvalidArgumentException('Email must not exceed 255 characters');
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$value}");
        }

        $this->value = strtolower($value);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
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