<?php
declare(strict_types=1);

namespace PenPay\Domain\User\Event;

use PenPay\Domain\Shared\Kernel\DomainEvent;
use PenPay\Domain\Shared\Kernel\UserId;

final readonly class DeviceAdded implements DomainEvent
{
    public function __construct(
        public UserId $userId,
        public string $deviceId,
        public string $platform,
        public string $model,
        public string $lastIp,
        public \DateTimeImmutable $registeredAt,
    ) {}

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function aggregateId(): string
    {
        return (string) $this->userId;
    }

    public function eventName(): string
    {
        return 'user.device.added';
    }

    public function toArray(): array
    {
        return [
            'user_id'     => (string) $this->userId,
            'device_id'   => $this->deviceId,
            'platform'    => $this->platform,
            'model'       => $this->model,
            'last_ip'     => $this->lastIp,
            'registered_at' => $this->registeredAt->format('Y-m-d H:i:s'),
        ];
    }
}