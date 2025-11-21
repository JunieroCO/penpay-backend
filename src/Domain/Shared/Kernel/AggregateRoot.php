<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\Kernel;

use PenPay\Domain\Shared\Kernel\DomainEvent;

/**
 * @template T of DomainEvent
 */
final class AggregateRoot
{
    /** @var list<T> */
    private array $recordedEvents;

    public function __construct()
    {
        $this->recordedEvents = [];
    }

    protected function raise(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * @return list<T>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }
}