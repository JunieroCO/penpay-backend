<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LockedRate
{
    public function __construct(
        public float $rate,                    // e.g. 145.50
        public Currency $from,
        public Currency $to,
        public DateTimeImmutable $lockedAt
    ) {
        if ($rate <= 0) {
            throw new InvalidArgumentException('FX rate must be positive');
        }
    }

    public static function lock(float $rate, Currency $from = Currency::USD, Currency $to = Currency::KES): self
    {
        return new self($rate, $from, $to, new DateTimeImmutable());
    }

    public function convert(Money $fromAmount): Money
    {
        if ($fromAmount->currency !== $this->from) {
            throw new InvalidArgumentException("Expected {$this->from->value}, got {$fromAmount->currency->value}");
        }

        $decimal = $fromAmount->toDecimal() * $this->rate;
        return Money::fromDecimal($decimal, $this->to);
    }

    public function toArray(): array
    {
        return [
            'rate' => $this->rate,
            'from' => $this->from->value,
            'to' => $this->to->value,
            'locked_at' => $this->lockedAt->format('Y-m-d H:i:s.u')
        ];
    }
}