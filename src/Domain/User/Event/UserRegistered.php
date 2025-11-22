<?php
declare(strict_types=1);

namespace PenPay\Domain\User\Event;

use PenPay\Domain\Shared\Kernel\DomainEvent;
use PenPay\Domain\Shared\Kernel\UserId;

final class UserRegistered implements DomainEvent
{
    public function __construct(
        public readonly UserId $userId,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $derivLoginId,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'userId'       => (string) $this->userId,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'derivLoginId' => $this->derivLoginId,
            'occurredAt'   => $this->occurredAt->format('c'),
        ];
    }
}