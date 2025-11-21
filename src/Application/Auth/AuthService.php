<?php
declare(strict_types=1);

namespace PenPay\Application\Auth;

use DateTimeImmutable;
use PenPay\Domain\Auth\Entity\RefreshToken;
use PenPay\Domain\Auth\Repository\RefreshTokenRepositoryInterface;
use PenPay\Domain\Auth\ValueObject\DeviceFingerprint;
use PenPay\Domain\Auth\ValueObject\TokenFamily;
use PenPay\Domain\User\Repository\UserRepositoryInterface;
use PenPay\Domain\User\ValueObject\Email;
use PenPay\Infrastructure\Auth\JWTHandler;
use RuntimeException;

final class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly RefreshTokenRepositoryInterface $refreshRepo,
        private readonly JWTHandler $jwt,
        private readonly int $refreshTtl = 2592000, // 30 days
    ) {}

    public function login(
        string $email,
        string $password,
        string $deviceId,
        string $userAgent
    ): array {
        $user = $this->userRepo->getByEmail(Email::fromString($email));
        
        if (!$user->passwordHash()->verify($password)) {
            throw new RuntimeException('Invalid credentials');
        }

        $fingerprint = DeviceFingerprint::fromString($deviceId, $userAgent);
        $family = TokenFamily::generate();

        // Revoke all previous tokens for this device (single session per device)
        foreach ($this->refreshRepo->findActiveByUserAndDevice($user->id(), $fingerprint->toString()) as $old) {
            $this->refreshRepo->revoke($old);
        }

        $plainRefresh = bin2hex(random_bytes(64));
        $expiresAt = (new DateTimeImmutable())->modify("+{$this->refreshTtl} seconds");

        $refreshToken = RefreshToken::issue(
            userId: $user->id(),
            deviceFingerprint: $fingerprint,
            rawToken: $plainRefresh,
            family: $family,
            expiresAt: $expiresAt
        );

        $this->refreshRepo->save($refreshToken);

        return [
            'access_token'  => $this->jwt->issueAccessToken(
                userId: (string) $user->id(),
                deviceFingerprint: $fingerprint->toString(),
                jti: bin2hex(random_bytes(16))
            ),
            'token_type'    => 'Bearer',
            'expires_in'    => 900,
            'refresh_token' => $plainRefresh,
            'refresh_expires_in' => $this->refreshTtl,
        ];
    }

    public function refresh(string $plainRefreshToken, string $deviceId, string $userAgent): array
    {
        $hash = hash('sha512', $plainRefreshToken);
        $token = $this->refreshRepo->findByHash($hash);

        if (!$token || !$token->isUsable(DeviceFingerprint::fromString($deviceId, $userAgent))) {
            // TOKEN REUSE DETECTED → KILL ALL TOKENS FOR USER
            if ($token) {
                $this->refreshRepo->revokeFamily($token->family()->toString());
            }
            throw new RuntimeException('Invalid or expired refresh token');
        }

        // Rotate: revoke old, issue new
        $this->refreshRepo->revoke($token);

        $newPlain = bin2hex(random_bytes(64));
        $newToken = $token->withNewUsage()->revoke(); // old one revoked
        $newRefresh = RefreshToken::issue(
            userId: $token->userId(),
            deviceFingerprint: $token->deviceFingerprint(),
            rawToken: $newPlain,
            family: $token->family(),
            expiresAt: (new DateTimeImmutable())->modify("+{$this->refreshTtl} seconds")
        );

        $this->refreshRepo->save($newRefresh);

        return [
            'access_token'  => $this->jwt->issueAccessToken(
                userId: (string) $token->userId(),
                deviceFingerprint: $token->deviceFingerprint()->toString(),
                jti: bin2hex(random_bytes(16))
            ),
            'token_type'    => 'Bearer',
            'expires_in'    => 900,
            'refresh_token' => $newPlain,
            'refresh_expires_in' => $this->refreshTtl,
        ];
    }

    public function logout(string $plainRefreshToken): void
    {
        $hash = hash('sha512', $plainRefreshToken);
        $token = $this->refreshRepo->findByHash($hash);

        if ($token) {
            $this->refreshRepo->revoke($token);
        }
    }

    public function logoutAllDevices(string $userId): void
    {
        $this->refreshRepo->revokeAllForUser(UserId::fromString($userId));
    }
}