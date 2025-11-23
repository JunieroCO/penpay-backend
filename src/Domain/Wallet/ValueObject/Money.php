<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\ValueObject;

use InvalidArgumentException;

final readonly class Money
{
    private const SCALE = 100; 

    public function __construct(
        public int $cents,
        public Currency $currency
    ) {
        if ($cents < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative');
        }
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public static function usd(int $cents): self
    {
        return new self($cents, Currency::USD);
    }

    public static function kes(int $cents): self
    {
        return new self($cents, Currency::KES);
    }

    public static function fromDecimal(string|float $amount, Currency $currency): self
    {
        $cents = (int) round((float) $amount * self::SCALE);
        return new self(abs($cents), $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($this->cents < $other->cents) {
            throw new InvalidArgumentException('Insufficient funds');
        }
        return new self($this->cents - $other->cents, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->cents > $other->cents;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    public function toDecimal(): float
    {
        return $this->cents / self::SCALE;
    }

    public function toString(): string
    {
        return sprintf('%.2f %s', $this->toDecimal(), $this->currency->value);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Cannot operate on different currencies');
        }
    }
}