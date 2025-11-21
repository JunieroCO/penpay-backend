<?php
declare(strict_types=1);

namespace PenPay\Domain\Auth\ValueObject;

final class DeviceFingerprint
{
    private function __construct(private readonly string $hash) {}

    public static function fromString(string $deviceId, string $userAgent): self
    {
        return new self(hash('sha256', $deviceId . '|' . $userAgent . '|penpay-2025-salt'));
    }

    public static function fromHash(string $hash): self
    {
        return new self($hash);
    }

    public function toString(): string
    {
        return $this->hash;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->hash, $other->hash);
    }

    public function __toString(): string
    {
        return $this->hash;
    }
}