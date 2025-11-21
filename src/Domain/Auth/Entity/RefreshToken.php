<?php
declare(strict_types=1);

namespace PenPay\Domain\Auth\Entity;

use DateTimeImmutable;
use DateTimeZone;
use PenPay\Domain\Auth\ValueObject\DeviceFingerprint;
use PenPay\Domain\Auth\ValueObject\RefreshTokenId;
use PenPay\Domain\Auth\ValueObject\TokenFamily;
use PenPay\Domain\Shared\Kernel\UserId;

final class RefreshToken
{
    private function __construct(
        private readonly RefreshTokenId $id,
        private readonly UserId $userId,
        private readonly DeviceFingerprint $deviceFingerprint,
        private readonly string $tokenHash,
        private readonly TokenFamily $family,
        private readonly DateTimeImmutable $expiresAt,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $lastUsedAt,
        private readonly bool $revoked = false,
    ) {}

    public static function issue(
        UserId $userId,
        DeviceFingerprint $deviceFingerprint,
        string $rawToken,
        TokenFamily $family,
        DateTimeImmutable $expiresAt,
    ): self {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new self(
            id: RefreshTokenId::generate(),
            userId: $userId,
            deviceFingerprint: $deviceFingerprint,
            tokenHash: hash('sha512', $rawToken),
            family: $family,
            expiresAt: $expiresAt,
            createdAt: $now,
            lastUsedAt: $now,
            revoked: false,
        );
    }

    public function withNewUsage(): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->deviceFingerprint,
            $this->tokenHash,
            $this->family,
            $this->expiresAt,
            $this->createdAt,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->revoked,
        );
    }

    public function revoke(): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->deviceFingerprint,
            $this->tokenHash,
            $this->family,
            $this->expiresAt,
            $this->createdAt,
            $this->lastUsedAt,
            true, // ← FIXED: bool, not DateTime
        );
    }

    public function isValidForDevice(DeviceFingerprint $fingerprint): bool
    {
        return $this->deviceFingerprint->equals($fingerprint);
    }

    public function isUsable(DeviceFingerprint $currentFingerprint): bool
    {
        return !$this->revoked
            && !$this->isExpired()
            && $this->isValidForDevice($currentFingerprint);
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    // === GETTERS ===
    public function id(): RefreshTokenId { return $this->id; }
    public function userId(): UserId { return $this->userId; }
    public function deviceFingerprint(): DeviceFingerprint { return $this->deviceFingerprint; }
    public function tokenHash(): string { return $this->tokenHash; }
    public function family(): TokenFamily { return $this->family; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function lastUsedAt(): DateTimeImmutable { return $this->lastUsedAt; }
    public function isRevoked(): bool { return $this->revoked; }
}