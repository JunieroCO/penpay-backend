<?php
declare(strict_types=1);

namespace PenPay\Application\Auth\Contract;

use PenPay\Domain\Shared\Kernel\UserId;

interface AuthServiceInterface
{
    /**
     * Authenticate user and generate tokens
     *
     * @return array{user_id: string, access_token: string, token_type: string, expires_in: int, refresh_token: string, refresh_expires_in: int}
     * @throws \RuntimeException
     */
    public function login(
        string $email,
        string $password,
        ?string $deviceId = null,
        ?string $userAgent = null,
    ): array;

    /**
     * Refresh access token using refresh token
     *
     * @return array{user_id: string, access_token: string, token_type: string, expires_in: int, refresh_token: string, refresh_expires_in: int}
     * @throws \RuntimeException
     */
    public function refresh(
        string $refreshToken,
        ?string $deviceId = null,
        ?string $userAgent = null,
    ): array;

    /**
     * Logout using refresh token
     */
    public function logoutByRefreshToken(
        string $refreshToken,
        ?string $deviceId = null,
        ?string $userAgent = null,
        bool $revokeFamily = false
    ): void;

    /**
     * Logout all devices for a user
     */
    public function logoutAllDevices(UserId $userId): void;
}

