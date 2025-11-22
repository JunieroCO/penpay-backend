<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\Kernel;

interface DomainEvent
{
    public function occurredAt(): \DateTimeImmutable;
    public function toArray(): array;
}