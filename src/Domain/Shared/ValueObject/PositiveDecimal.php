<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\ValueObject;

use InvalidArgumentException;

final readonly class PositiveDecimal
{
    public function __construct(
        public string $value, // Stored as string for precision
        public int $scale = 2
    ) {
        if (!preg_match('/^\d+(\.\d{1,' . $scale . '})?$/', $value)) {
            throw new InvalidArgumentException("Invalid decimal format: {$value}");
        }
        if (bccomp($value, '0', $scale) <= 0) {
            throw new InvalidArgumentException('Value must be positive');
        }
    }

    public static function fromFloat(float $value, int $scale = 2): self
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('Value must be positive');
        }
        return new self(number_format($value, $scale, '.', ''), $scale);
    }

    public function toFloat(): float
    {
        return (float) $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}