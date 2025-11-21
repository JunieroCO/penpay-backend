<?php
declare(strict_types=1);

namespace PenPay\Application\User\Command;

use PenPay\Domain\Shared\Kernel\UserId;

final class RegisterDevice
{
    public function __construct(
        public readonly UserId $userId,
        public readonly string $deviceId,
        public readonly string $platform,
        public readonly ?string $model = null,
        public readonly ?string $ipAddress = null,
    ) {}
}