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
    KycSnapshot
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
        $user = new self($id, $email, $phone, $derivLoginId, $kyc, $passwordHash);

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
            devices: $this->devices,
        );

        $updated->raise(new PasswordChanged(userId: $this->id));

        return $updated;
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

    // Immutable getters
    public function id(): UserId { return $this->id; }
    public function email(): Email { return $this->email; }
    public function phone(): PhoneNumber { return $this->phone; }
    public function derivLoginId(): DerivLoginId { return $this->derivLoginId; }
    public function kyc(): KycSnapshot { return $this->kyc; }
    public function passwordHash(): PasswordHash { return $this->passwordHash; }
}