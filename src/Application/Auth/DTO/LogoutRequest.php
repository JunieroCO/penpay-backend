<?php
declare(strict_types=1);

namespace PenPay\Application\Auth\DTO;

use InvalidArgumentException;

final readonly class LogoutRequest
{
    private function __construct(
        public string  $refreshToken,
        public ?string $deviceId = null,
        public ?string $userAgent = null,
        public ?bool   $revokeAllDevices = null,
    ) {}

    public static function fromArray(array $data, ?string $userAgent = null): self
    {
        $token = $data['refresh_token'] ?? throw new InvalidArgumentException('refresh_token is required');
        $deviceId = $data['device_id'] ?? null;
        $revokeAllDevices = $data['revoke_all_devices'] ?? null;

        if (!is_string($token) || trim($token) === '') {
            throw new InvalidArgumentException('refresh_token must be a non-empty string');
        }
        if ($deviceId !== null && !is_string($deviceId)) {
            throw new InvalidArgumentException('device_id must be a string or null');
        }
        if ($revokeAllDevices !== null && !is_bool($revokeAllDevices)) {
            throw new InvalidArgumentException('revoke_all_devices must be a boolean or null');
        }

        return new self(
            refreshToken: $token,
            deviceId: $deviceId,
            userAgent: $userAgent,
            revokeAllDevices: $revokeAllDevices,
        );
    }
}

