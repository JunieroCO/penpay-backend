<?php
namespace PenPay\Domain\Shared\Contracts;

interface DomainEventPublisher
{
    /** @param array<object> $events */
    public function publish(array $events): void;
}