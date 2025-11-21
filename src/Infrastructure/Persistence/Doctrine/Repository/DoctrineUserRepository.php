<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Doctrine\Repository;

use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\Aggregate\User;
use PenPay\Domain\User\Repository\UserRepositoryInterface;
use PenPay\Domain\User\Exception\UserNotFoundException;
use PenPay\Domain\User\ValueObject\Email;
use PenPay\Domain\User\Entity\Device;
use PenPay\Infrastructure\Persistence\Doctrine\Entity\User as UserEntity;
use PenPay\Infrastructure\Persistence\Doctrine\Entity\UserDevice;
use PenPay\Infrastructure\Queue\RedisStreamPublisher;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RedisStreamPublisher $eventPublisher,
    ) {}

    public function save(User $user): void
    {
        $this->em->transactional(function () use ($user) {
            $entity = $this->em->find(UserEntity::class, $user->id()) ?? new UserEntity();

            $entity->setId($user->id());
            $entity->setEmail($user->email());
            $entity->setPhone($user->phone());
            $entity->setDerivLoginId($user->derivLoginId());
            $entity->setPasswordHash($user->passwordHash());
            $entity->setKyc($user->kyc());

            // Sync devices — full replace (immutable style)
            foreach ($entity->getDevices() as $existing) {
                $this->em->remove($existing);
            }

            foreach ($user->devices() as $device) {
                $deviceEntity = new UserDevice();
                $deviceEntity->setUser($entity);
                $deviceEntity->setDeviceId($device->deviceId());
                $deviceEntity->setPlatform($device->platform());
                $deviceEntity->setModel($device->model());
                $deviceEntity->setLastIp($device->lastIp());
                $deviceEntity->setRegisteredAt($device->registeredAt());
                $this->em->persist($deviceEntity);
            }

            $this->em->persist($entity);
            $this->em->flush();

            // CORRECT: use toArray() — standard in our domain events
            foreach ($user->releaseEvents() as $event) {
                $this->eventPublisher->publish('user.events', [
                    'event_type'   => $event::class,
                    'payload'      => $event->toArray(),
                    'occurred_at'   => $event->occurredAt->format('c'),
                ]);
            }
        });
    }

    public function getById(UserId $id): User
    {
        $entity = $this->em->find(UserEntity::class, $id);
        if (!$entity) {
            throw UserNotFoundException::withId((string) $id);
        }
        return $this->toDomain($entity);
    }

    public function getByDerivLoginId(string $derivLoginId): User
    {
        $entity = $this->em->getRepository(UserEntity::class)->findOneBy(['derivLoginId' => $derivLoginId]);
        if (!$entity) {
            throw UserNotFoundException::withDerivLoginId($derivLoginId);
        }
        return $this->toDomain($entity);
    }

    public function getByPhone(string $e164Phone): User
    {
        $entity = $this->em->getRepository(UserEntity::class)->findOneBy(['phone' => $e164Phone]);
        if (!$entity) {
            throw UserNotFoundException::withPhone($e164Phone);
        }
        return $this->toDomain($entity);
    }

    public function getByEmail(Email $email): User
    {
        $entity = $this->em->getRepository(UserEntity::class)->findOneBy(['email' => (string) $email]);
        if (!$entity) {
            throw UserNotFoundException::withEmail((string) $email);
        }
        return $this->toDomain($entity);
    }

    public function existsByDerivLoginId(string $derivLoginId): bool
    {
        return $this->em->getRepository(UserEntity::class)->count(['derivLoginId' => $derivLoginId]) > 0;
    }

    public function existsByPhone(string $e164Phone): bool
    {
        return $this->em->getRepository(UserEntity::class)->count(['phone' => $e164Phone]) > 0;
    }

    public function existsByEmail(Email $email): bool
    {
        return $this->em->getRepository(UserEntity::class)->count(['email' => (string) $email]) > 0;
    }

    private function toDomain(UserEntity $entity): User
    {
        $devices = $entity->getDevices()->map(fn(UserDevice $d) => Device::register(
            userId: $entity->getId(),
            deviceId: $d->getDeviceId(),
            platform: $d->getPlatform(),
            model: $d->getModel(),
            lastIp: $d->getLastIp(),
            registeredAt: $d->getRegisteredAt(),
        ));

        $user = User::register(
            id: $entity->getId(),
            email: $entity->getEmail(),
            phone: $entity->getPhone(),
            derivLoginId: $entity->getDerivLoginId(),
            kyc: $entity->getKyc(),
            passwordHash: $entity->getPasswordHash(),
        );

        foreach ($devices as $device) {
            $user = $user->addDevice($device);
        }

        return $user;
    }
}