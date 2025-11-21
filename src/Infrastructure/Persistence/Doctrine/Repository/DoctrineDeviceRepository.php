<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\Entity\Device;
use PenPay\Domain\User\Repository\DeviceRepositoryInterface;
use PenPay\Infrastructure\Persistence\Doctrine\Entity\UserDevice;

final class DoctrineDeviceRepository implements DeviceRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    public function save(Device $device): void
    {
        $entity = $this->em->find(UserDevice::class, $device->deviceId())
            ?? new UserDevice();

        $entity->setUserId((string) $device->userId());
        $entity->setDeviceId($device->deviceId());
        $entity->setPlatform($device->platform());
        $entity->setModel($device->model());
        $entity->setLastIp($device->lastIp());
        $entity->setRegisteredAt($device->registeredAt());

        $this->em->persist($entity);
        // NO FLUSH — controlled by UserRepository
    }

    /**
     * @return Collection<int, Device>
     */
    public function getByUserId(UserId $userId): Collection
    {
        $entities = $this->em->getRepository(UserDevice::class)
            ->findBy(['userId' => (string) $userId]);

        $devices = array_map(fn(UserDevice $e) => Device::register(
            userId: UserId::fromString($e->getUserId()),
            deviceId: $e->getDeviceId(),
            platform: $e->getPlatform(),
            model: $e->getModel(),
            lastIp: $e->getLastIp(),
            registeredAt: $e->getRegisteredAt(),
        ), $entities);

        /** @var Collection<int, Device> $collection */
        $collection = new ArrayCollection($devices);

        return $collection;
    }

    public function getByDeviceId(string $deviceId): ?Device
    {
        $entity = $this->em->getRepository(UserDevice::class)
            ->findOneBy(['deviceId' => $deviceId]);

        if (!$entity) {
            return null;
        }

        return Device::register(
            userId: UserId::fromString($entity->getUserId()),
            deviceId: $entity->getDeviceId(),
            platform: $entity->getPlatform(),
            model: $entity->getModel(),
            lastIp: $entity->getLastIp(),
            registeredAt: $entity->getRegisteredAt(),
        );
    }

    public function existsByDeviceId(string $deviceId): bool
    {
        return $this->em->getRepository(UserDevice::class)
            ->count(['deviceId' => $deviceId]) > 0;
    }
}