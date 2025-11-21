<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\ValueObject\{
    Email,
    PhoneNumber,
    DerivLoginId,
    PasswordHash,
    KycSnapshot
};

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uq_user_deriv_login_id', columns: ['deriv_login_id'])]
#[ORM\UniqueConstraint(name: 'uq_user_phone', columns: ['phone'])]
#[ORM\UniqueConstraint(name: 'uq_user_email', columns: ['email'])]
#[ORM\Index(name: 'idx_user_deriv_login_id', columns: ['deriv_login_id'])]
#[ORM\Index(name: 'idx_user_phone', columns: ['phone'])]
final class User
{
    #[ORM\Id]
    #[ORM\Column(type: 'user_id')]
    private UserId $id;

    #[ORM\Column(type: 'email', length: 255, unique: true)]
    private Email $email;

    #[ORM\Column(type: 'phone_number', length: 20, unique: true)]
    private PhoneNumber $phone;

    #[ORM\Column(type: 'deriv_login_id', unique: true)]
    private DerivLoginId $derivLoginId;

    #[ORM\Column(type: 'password_hash', length: 255)]
    private PasswordHash $passwordHash;

    #[ORM\Embedded(class: KycSnapshot::class, columnPrefix: 'kyc_')]
    private KycSnapshot $kyc;

    /** @var Collection<int, UserDevice> */
    #[ORM\OneToMany(
        mappedBy: 'user',
        targetEntity: UserDevice::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $devices;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->devices = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    // === Getters only — no setters, no mutation ===
    public function getId(): UserId { return $this->id; }
    public function getEmail(): Email { return $this->email; }
    public function getPhone(): PhoneNumber { return $this->phone; }
    public function getDerivLoginId(): DerivLoginId { return $this->derivLoginId; }
    public function getPasswordHash(): PasswordHash { return $this->passwordHash; }
    public function getKyc(): KycSnapshot { return $this->kyc; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, UserDevice> */
    public function getDevices(): Collection
    {
        return $this->devices;
    }

    // === Setters — used ONLY by DoctrineUserRepository ===
    public function setId(UserId $id): void { $this->id = $id; }
    public function setEmail(Email $email): void { $this->email = $email; }
    public function setPhone(PhoneNumber $phone): void { $this->phone = $phone; }
    public function setDerivLoginId(DerivLoginId $id): void { $this->derivLoginId = $id; }
    public function setPasswordHash(PasswordHash $hash): void { $this->passwordHash = $hash; }
    public function setKyc(KycSnapshot $kyc): void { $this->kyc = $kyc; }
}