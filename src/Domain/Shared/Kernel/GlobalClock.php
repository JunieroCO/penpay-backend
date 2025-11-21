<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\Kernel;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Immutable, pure, testable UTC clock
 * Used everywhere: events, entities, value objects
 */
final readonly class Clock
{
    private function __construct(
        private DateTimeImmutable $frozen
    ) {}

    public static function utc(): self
    {
        return new self(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public static function frozen(DateTimeImmutable $fixed): self
    {
        return new self($fixed->setTimezone(new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozen;
    }
}