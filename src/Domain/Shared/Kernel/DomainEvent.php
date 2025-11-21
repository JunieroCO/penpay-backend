<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\Kernel;

use DateTimeImmutable;

abstract class DomainEvent
{
    public DateTimeImmutable $occurredAt;

    public function __construct(?DateTimeImmutable $occurredAt = null)
    {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Convert event payload to array for publishing */
    abstract public function toArray(): array;
}