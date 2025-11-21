<?php
declare(strict_types=1);

namespace PenPay\Domain\User\Entity;

use PenPay\Domain\Shared\Kernel\UserId;
use DateTimeImmutable;

final class Device
{
    private function __construct(
        private readonly UserId $userId,
        public readonly string $deviceId,
        public readonly string $platform,
        public readonly ?string $model,
        public readonly ?string $lastIp,
        public readonly DateTimeImmutable $registeredAt,
    ) {}

    /**
     * Factory method — used by repository to reconstruct from DB
     */
    public static function register(
        UserId $userId,
        string $deviceId,
        string $platform,
        ?string $model = null,
        ?string $lastIp = null,
        ?DateTimeImmutable $registeredAt = null,
    ): self {
        return new self(
            userId: $userId,
            deviceId: $deviceId,
            platform: $platform,
            model: $model,
            lastIp: $lastIp,
            registeredAt: $registeredAt ?? new DateTimeImmutable(),
        );
    }

    // GETTERS — REQUIRED FOR REPOSITORY MAPPING
    public function userId(): UserId { return $this->userId; }
    public function deviceId(): string { return $this->deviceId; }
    public function platform(): string { return $this->platform; }
    public function model(): ?string { return $this->model; }
    public function lastIp(): ?string { return $this->lastIp; }
    public function registeredAt(): DateTimeImmutable { return $this->registeredAt; }

    public function equals(self $other): bool
    {
        return $this->deviceId === $other->deviceId;
    }
}