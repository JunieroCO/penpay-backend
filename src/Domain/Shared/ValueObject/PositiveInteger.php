<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\ValueObject;

use InvalidArgumentException;

final readonly class PositiveInteger
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('Value must be a positive integer');
        }
    }

    public static function create(int $value): self
    {
        return new self($value);
    }
}