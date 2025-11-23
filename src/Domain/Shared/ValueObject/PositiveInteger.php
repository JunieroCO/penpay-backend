<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\ValueObject;

use InvalidArgumentException;

final readonly class PositiveInteger  
{
    public function __construct(
        public int $value
    ) {
        if ($value <= 0) {
            throw new InvalidArgumentException('Value must be positive');
        }
    }

    public static function fromInt(int $value): self
    {
        return new self($value);
    }
    

    public function toInt(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}