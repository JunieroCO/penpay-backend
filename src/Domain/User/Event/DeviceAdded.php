<?php
declare(strict_types=1);

namespace PenPay\Domain\User\Event;

use PenPay\Domain\Shared\Kernel\DomainEvent;
use PenPay\Domain\Shared\Kernel\UserId;
use DateTimeImmutable;

final readonly class DeviceAdded extends DomainEvent
{
    public function __construct(
        public UserId $userId,
        public string $deviceId,
        public string $platform,
        public ?string $model,
        public ?string $lastIp,
        public DateTimeImmutable $registeredAt,
    ) {
        parent::__construct();
    }
}