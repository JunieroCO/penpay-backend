<?php
declare(strict_types=1);

namespace PenPay\Domain\User\ValueObject;

use InvalidArgumentException;

final readonly class PhoneNumber
{
    private string $e164; // Canonical internal format: +2547xxxxxxxx

    private function __construct(string $e164)
    {
        if (!preg_match('/^\+\d{1,15}$/', $e164)) {
            throw new InvalidArgumentException('Phone number must be in E.164 format');
        }
        if (strlen($e164) > 16) { // + followed by max 15 digits
            throw new InvalidArgumentException('Phone number exceeds E.164 maximum length');
        }
        $this->e164 = $e164;
    }

    /**
     * Kenya-first creation — accepts:
     *   "0712345678", "254712345678", "+254712345678"
     */
    public static function fromKenyan(string $input): self
    {
        $digits = preg_replace('/\D+/', '', $input);

        return match (true) {
            preg_match('/^2547[0-9]{8}$/', $digits) => new self('+254' . substr($digits, 3)),
            preg_match('/^07[0-9]{8}$/', $digits)  => new self('+254' . substr($digits, 1)),
            preg_match('/^\+2547[0-9]{8}$/', $input) => new self($input),
            default => throw new InvalidArgumentException('Invalid Kenyan phone number'),
        };
    }

    /** Only for future international support — explicitly named */
    public static function fromE164(string $e164): self
    {
        return new self($e164); // will validate format
    }

    public function toE164(): string
    {
        return $this->e164;
    }

    public function nationalFormat(): string
    {
        return substr($this->e164, 4); // "0712345678"
    }

    public function equals(self $other): bool
    {
        return $this->e164 === $other->e164;
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}