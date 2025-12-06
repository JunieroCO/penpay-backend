<?php
declare(strict_types=1);

namespace PenPay\Domain\User\ValueObject;

use InvalidArgumentException;

final readonly class PhoneE164
{
    private string $value;

    private function __construct(string $value)
    {
        if (!preg_match('/^\+[1-9]\d{1,14}$/', $value)) {
            throw new InvalidArgumentException("Invalid E.164 phone number: {$value}");
        }
        $this->value = $value;
    }

    public static function fromString(string $phone): self
    {
        $clean = preg_replace('/\D/', '', $phone);
        if (str_starts_with($clean, '0')) {
            $clean = '254' . substr($clean, 1); // Kenya
        } elseif (!str_starts_with($clean, '254') && strlen($clean) === 9) {
            $clean = '254' . $clean;
        } elseif (strlen($clean) === 12 && str_starts_with($clean, '254')) {
            // already good
        } else {
            throw new InvalidArgumentException("Cannot normalize phone: {$phone}");
        }

        return new self('+' . $clean);
    }

    public static function fromE164(string $e164): self
    {
        return new self($e164);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}