<?php
declare(strict_types=1);

namespace PenPay\Application\Auth\DTO;

use InvalidArgumentException;

final readonly class RefreshTokenRequest
{
    private function __construct(
        public string  $refreshToken,
        public ?string $deviceId = null,
        public ?string $userAgent = null,
    ) {}

    public static function fromArray(array $data, ?string $userAgent = null): self
    {
        $token    = $data['refresh_token'] ?? throw new InvalidArgumentException('refresh_token is required');
        $deviceId = $data['device_id']     ?? null;

        if (!is_string($token) || trim($token) === '') {
            throw new InvalidArgumentException('refresh_token must be a non-empty string');
        }

        return new self(
            refreshToken: $token,
            deviceId:     $deviceId,
            userAgent:    $userAgent,
        );
    }
}