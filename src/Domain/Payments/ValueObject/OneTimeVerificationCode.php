<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\ValueObject;

use InvalidArgumentException;

final readonly class OneTimeVerificationCode
{
    private const LENGTH = 8;
    private const PATTERN = '/^[A-Za-z0-9]{8}$/';

    private function __construct(
        private string $code
    ) {}

    public static function fromString(string $code): self
    {
        $code = trim($code);
        
        if ($code === '') {
            throw new InvalidArgumentException(
                'Verification code cannot be empty'
            );
        }

        if (!preg_match(self::PATTERN, $code)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Deriv verification code must be %d alphanumeric characters',
                    self::LENGTH
                )
            );
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

    public function __toString(): string
    {
        return $this->code;
    }
}