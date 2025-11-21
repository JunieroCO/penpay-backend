<?php
declare(strict_types=1);

namespace PenPay\Domain\User\Repository;

use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\Entity\Device;
use Doctrine\Common\Collections\Collection;

interface DeviceRepositoryInterface
{
    public function save(Device $device): void;

    /** @return Collection<int, Device> */
    public function getByUserId(UserId $userId): Collection;

    public function getByDeviceId(string $deviceId): ?Device;

    public function existsByDeviceId(string $deviceId): bool;
}
