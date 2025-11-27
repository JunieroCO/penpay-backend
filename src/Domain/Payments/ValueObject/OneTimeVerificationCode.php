<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\ValueObject;

use InvalidArgumentException;

final class OneTimeVerificationCode
{
    private function __construct(private string $code) {}

    public static function fromString(string $code): self
    {
        $code = trim($code);
        if ($code === '') {
            throw new InvalidArgumentException('Verification code cannot be empty');
        }
        if (!preg_match('/^[A-Za-z0-9]{8}$/', $code)) {
            throw new InvalidArgumentException('Deriv verification code must be 8 alphanumeric characters');
        }
        return new self(strtoupper($code));
    }

    public function toString(): string
    {
        return $this->code;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}