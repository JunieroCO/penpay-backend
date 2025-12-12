<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\ValueObject;

use InvalidArgumentException;

final readonly class PhoneNumber
{
    private string $e164; 

    private function __construct(string $e164)
    {
        if (!preg_match('/^\+\d{1,15}$/', $e164)) {
            throw new InvalidArgumentException('Phone number must be in E.164 format');
        }
        if (strlen($e164) > 16) { 
            throw new InvalidArgumentException('Phone number exceeds E.164 maximum length');
        }
        $this->e164 = $e164;
    }

    public static function fromKenyan(string $input): self
    {
        $digits = preg_replace('/\D+/', '', $input);

        // Handle +2547xxxxxxxx (13 chars with +)
        if (preg_match('/^\+2547[0-9]{8}$/', $input)) {
            return new self($input);
        }
        
        // Handle 2547xxxxxxxx (12 digits)
        if (preg_match('/^2547[0-9]{8}$/', $digits)) {
            return new self('+254' . substr($digits, 3));
        }
        
        // Handle 07xxxxxxxx (10 digits)
        if (preg_match('/^07[0-9]{8}$/', $digits)) {
            return new self('+254' . substr($digits, 1));
        }

        throw new InvalidArgumentException('Invalid Kenyan phone number');
    }

    /** Only for future international support — explicitly named */
    public static function fromE164(string $e164): self
    {
        return new self($e164); 
    }

    public function toE164(): string
    {
        return $this->e164;
    }

    public function nationalFormat(): string
    {
        return substr($this->e164, 4); 
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