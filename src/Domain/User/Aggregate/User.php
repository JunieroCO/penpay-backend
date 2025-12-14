<?php
declare(strict_types=1);

namespace PenPay\Domain\User\Aggregate;

use PenPay\Domain\Shared\Kernel\AggregateRoot;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\ValueObject\{
    Email,
    PhoneNumber,
    DerivLoginId,
    PasswordHash,
    KycSnapshot,
    UserStatus
};
use PenPay\Domain\User\Entity\Device;
use PenPay\Domain\User\Event\{
    UserRegistered,
    DeviceAdded,
    PasswordChanged
};
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use InvalidArgumentException;

final class User extends AggregateRoot
{
    private const MAX_DEVICES = 2;

    private function __construct(
        public readonly UserId $id,
        public readonly Email $email,
        public readonly PhoneNumber $phone,
        public readonly DerivLoginId $derivLoginId,
        public readonly KycSnapshot $kyc,
        public readonly PasswordHash $passwordHash,
        private UserStatus $status,
        private bool $isVerified,
        private bool $onboardingCompleted,
        private array $devices = [],
    ) {
        parent::__construct();
    }

    public static function register(
        UserId $id,
        Email $email,
        PhoneNumber $phone,
        DerivLoginId $derivLoginId,
        KycSnapshot $kyc,
        PasswordHash $passwordHash,
    ): self {
        $user = new self(
            $id, 
            $email, 
            $phone, 
            $derivLoginId, 
            $kyc, 
            $passwordHash, 
            UserStatus::pending(),
            false, // isVerified starts as false
            false  // onboardingCompleted starts as false
        );

        $user->raise(new UserRegistered(
            userId: $id,
            email: (string) $email,
            phone: $phone->toE164(),
            derivLoginId: (string) $derivLoginId,
        ));

        return $user;
    }

    public function addDevice(Device $newDevice): self
    {
        if ($this->hasDeviceWithId($newDevice->deviceId)) {
            return $this; // idempotent
        }

        if (count($this->devices) >= self::MAX_DEVICES) {
            throw new InvalidArgumentException('Maximum 2 devices allowed per user');
        }

        $updated = new self(
            id: $this->id,
            email: $this->email,
            phone: $this->phone,
            derivLoginId: $this->derivLoginId,
            kyc: $this->kyc,
            passwordHash: $this->passwordHash,
            status: $this->status,
            isVerified: $this->isVerified,
            onboardingCompleted: $this->onboardingCompleted,
            devices: [...$this->devices, $newDevice],
        );

        $updated->raise(new DeviceAdded(
            userId: $this->id,
            deviceId: $newDevice->deviceId,
            platform: $newDevice->platform,
            model: $newDevice->model,
            lastIp: $newDevice->lastIp,
            registeredAt: $newDevice->registeredAt,
        ));

        return $updated;
    }

    public function changePassword(PasswordHash $newHash): self
    {
        if ($this->passwordHash->equals($newHash)) {
            return $this;
        }

        $updated = new self(
            id: $this->id,
            email: $this->email,
            phone: $this->phone,
            derivLoginId: $this->derivLoginId,
            kyc: $this->kyc,
            passwordHash: $newHash,
            status: $this->status,
            isVerified: $this->isVerified,
            onboardingCompleted: $this->onboardingCompleted,
            devices: $this->devices,
        );

        $updated->raise(new PasswordChanged(userId: $this->id));

        return $updated;
    }

    public function markAsVerified(): self
    {
        if ($this->isVerified) {
            return $this;
        }

        return new self(
            id: $this->id,
            email: $this->email,
            phone: $this->phone,
            derivLoginId: $this->derivLoginId,
            kyc: $this->kyc,
            passwordHash: $this->passwordHash,
            status: $this->status,
            isVerified: true,
            onboardingCompleted: $this->onboardingCompleted,
            devices: $this->devices,
        );
    }

    public function completeOnboarding(): self
    {
        if ($this->onboardingCompleted) {
            return $this;
        }

        return new self(
            id: $this->id,
            email: $this->email,
            phone: $this->phone,
            derivLoginId: $this->derivLoginId,
            kyc: $this->kyc,
            passwordHash: $this->passwordHash,
            status: $this->status,
            isVerified: $this->isVerified,
            onboardingCompleted: true,
            devices: $this->devices,
        );
    }

    public function changeStatus(UserStatus $newStatus): self
    {
        if ($this->status->equals($newStatus)) {
            return $this;
        }

        return new self(
            id: $this->id,
            email: $this->email,
            phone: $this->phone,
            derivLoginId: $this->derivLoginId,
            kyc: $this->kyc,
            passwordHash: $this->passwordHash,
            status: $newStatus,
            isVerified: $this->isVerified,
            onboardingCompleted: $this->onboardingCompleted,
            devices: $this->devices,
        );
    }

    public function activate(): self
    {
        return $this->changeStatus(UserStatus::active());
    }

    public function suspend(): self
    {
        return $this->changeStatus(UserStatus::suspended());
    }

    public function ban(): self
    {
        return $this->changeStatus(UserStatus::banned());
    }

    public function close(): self
    {
        return $this->changeStatus(UserStatus::closed());
    }

    private function hasDeviceWithId(string $deviceId): bool
    {
        foreach ($this->devices as $device) {
            if ($device->deviceId === $deviceId) {
                return true;
            }
        }
        return false;
    }

    /** @return ArrayCollection<int, Device> */
    public function devices(): ArrayCollection
    {
        return new ArrayCollection($this->devices);
    }

    public static function reconstitute(
        UserId $id,
        Email $email,
        PhoneNumber $phone,
        DerivLoginId $derivLoginId,
        KycSnapshot $kyc,
        PasswordHash $passwordHash,
        UserStatus $status,
        bool $isVerified,
        bool $onboardingCompleted,
        array $devices = []
    ): self {
        $user = new self(
            id: $id,
            email: $email,
            phone: $phone,
            derivLoginId: $derivLoginId,
            kyc: $kyc,
            passwordHash: $passwordHash,
            status: $status,
            isVerified: $isVerified,
            onboardingCompleted: $onboardingCompleted,
            devices: $devices,
        );

        return $user;
    }

    // Immutable getters
    public function id(): UserId { return $this->id; }
    public function email(): Email { return $this->email; }
    public function phone(): PhoneNumber { return $this->phone; }
    public function derivLoginId(): DerivLoginId { return $this->derivLoginId; }
    public function kyc(): KycSnapshot { return $this->kyc; }
    public function passwordHash(): PasswordHash { return $this->passwordHash; }
    public function status(): UserStatus { return $this->status; }
    public function isVerified(): bool { return $this->isVerified; }
    public function isSuspended(): bool { return $this->status->isSuspended(); }
    public function isBanned(): bool { return $this->status->isBanned(); }
    public function hasCompletedOnboarding(): bool { return $this->onboardingCompleted; }
}