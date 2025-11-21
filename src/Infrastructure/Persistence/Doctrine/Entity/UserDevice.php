<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use PenPay\Domain\Shared\Kernel\UserId;

#[ORM\Entity]
#[ORM\Table(name: 'user_devices')]
#[ORM\UniqueConstraint(name: 'uq_user_device', columns: ['user_id', 'device_id'])]
final class UserDevice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'devices')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 36)]
    private string $userId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $deviceId;

    #[ORM\Column(type: 'string', length: 50)]
    private string $platform;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $lastIp = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $registeredAt;

    public function setUser(User $user): void
    {
        $this->user = $user;
        $this->userId = (string) $user->getId(); // CORRECT: UserId is Stringable
    }

    public function getUser(): User
    {
        return $this->user;
    }

    // === GETTERS & SETTERS ===
    public function getId(): ?int { return $this->id; }

    public function getUserId(): string { return $this->userId; }
    public function setUserId(string $userId): void { $this->userId = $userId; }

    public function getDeviceId(): string { return $this->deviceId; }
    public function setDeviceId(string $deviceId): void { $this->deviceId = $deviceId; }

    public function getPlatform(): string { return $this->platform; }
    public function setPlatform(string $platform): void { $this->platform = $platform; }

    public function getModel(): ?string { return $this->model; }
    public function setModel(?string $model): void { $this->model = $model; }

    public function getLastIp(): ?string { return $this->lastIp; }
    public function setLastIp(?string $ip): void { $this->lastIp = $ip; }

    public function getRegisteredAt(): \DateTimeImmutable { return $this->registeredAt; }
    public function setRegisteredAt(\DateTimeImmutable $at): void { $this->registeredAt = $at; }
}