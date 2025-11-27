<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LockedRate
{
    public function __construct(
        public float $rate,
        public Currency $from,
        public Currency $to,
        public DateTimeImmutable $lockedAt,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($rate <= 0) {
            throw new InvalidArgumentException('FX rate must be positive');
        }
        if ($expiresAt <= $lockedAt) {
            throw new InvalidArgumentException('Expiry must be after lock time');
        }
    }

    public static function lock(
        float $rate,
        Currency $from = Currency::USD,
        Currency $to = Currency::KES,
        ?DateTimeImmutable $expiresAt = null
    ): self {
        $lockedAt = new DateTimeImmutable();
        $expiresAt ??= (clone $lockedAt)->modify('+15 minutes');
        return new self($rate, $from, $to, $lockedAt, $expiresAt);
    }

    // REQUIRED METHODS — FOR ORCHESTRATOR
    public function rate(): float
    {
        return $this->rate;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function convert(Money $fromAmount): Money
    {
        if ($fromAmount->currency !== $this->from) {
            throw new InvalidArgumentException("Expected {$this->from->value}, got {$fromAmount->currency->value}");
        }
        return Money::fromDecimal($fromAmount->toDecimal() * $this->rate, $this->to);
    }

    public function toArray(): array
    {
        return [
            'rate'       => $this->rate,
            'from'       => $this->from->value,
            'to'         => $this->to->value,
            'locked_at'  => $this->lockedAt->format('c'),
            'expires_at' => $this->expiresAt->format('c'),
        ];
    }
}