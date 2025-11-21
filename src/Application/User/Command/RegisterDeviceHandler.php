<?php
declare(strict_types=1);

namespace PenPay\Application\User\Command;

use PenPay\Domain\User\Repository\UserRepositoryInterface;
use PenPay\Domain\User\Repository\DeviceRepositoryInterface;
use PenPay\Domain\User\Entity\Device;
use PenPay\Domain\User\Exception\DeviceAlreadyRegistered;

final class RegisterDeviceHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly DeviceRepositoryInterface $deviceRepository,
    ) {}

    public function __invoke(RegisterDevice $command): void
    {
        $user = $this->userRepository->getById($command->userId);

        // Deduplication — critical for security
        if ($this->deviceRepository->existsByDeviceId($command->deviceId)) {
            throw new DeviceAlreadyRegistered($command->deviceId);
        }

        $device = Device::register(
            userId: $user->id(),
            deviceId: $command->deviceId,
            platform: $command->platform,
            model: $command->model,
            lastIp: $command->ipAddress,
        );

        $updatedUser = $user->addDevice($device);

        $this->userRepository->save($updatedUser);
    }
}