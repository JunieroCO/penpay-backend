<?php
declare(strict_types=1);

namespace PenPay\Application\Auth;

use DateTimeImmutable;
use PenPay\Application\Auth\Contract\AuthServiceInterface;
use PenPay\Domain\Auth\Entity\RefreshToken;
use PenPay\Domain\Auth\Repository\RefreshTokenRepositoryInterface;
use PenPay\Domain\Auth\ValueObject\DeviceFingerprint;
use PenPay\Domain\Auth\ValueObject\TokenFamily;
use PenPay\Domain\User\Repository\UserRepositoryInterface;
use PenPay\Domain\User\ValueObject\Email;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Infrastructure\Auth\JWTHandler;
use RuntimeException;

final class AuthService implements AuthServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly RefreshTokenRepositoryInterface $refreshRepo,
        private readonly JWTHandler $jwt,
        private readonly int $refreshTtl = 2_592_000,
    ) {}

    public function login(
        string $email,
        string $password,
        ?string $deviceId = null,
        ?string $userAgent = null,
    ): array {
        $user = $this->userRepo->getByEmail(Email::fromString($email));

        if (!$user->passwordHash()->verify($password)) {
            throw new RuntimeException('Invalid credentials');
        }

        $fingerprint = DeviceFingerprint::fromString($deviceId ?? 'unknown', $userAgent ?? 'unknown');
        $family = TokenFamily::generate();

        // Enforce single session per device
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
            'user_id'             => (string) $user->id(),
            'access_token'        => $this->jwt->issueAccessToken(
                userId: (string) $user->id(),
                deviceFingerprint: $fingerprint->toString(),
                jti: bin2hex(random_bytes(16))
            ),
            'token_type'          => 'Bearer',
            'expires_in'          => 900,
            'refresh_token'       => $plainRefresh,
            'refresh_expires_in'  => $this->refreshTtl,
        ];
    }

    public function refresh(
        string $refreshToken,
        ?string $deviceId = null,
        ?string $userAgent = null,
    ): array {
        $hash = hash('sha512', $refreshToken);
        $token = $this->refreshRepo->findByHash($hash);

        $fingerprint = DeviceFingerprint::fromString($deviceId ?? 'unknown', $userAgent ?? 'unknown');

        if (!$token || !$token->isUsable($fingerprint)) {
            if ($token) {
                $this->refreshRepo->revokeFamily($token->family()->toString());
            }
            throw new RuntimeException('Invalid or expired refresh token');
        }

        $this->refreshRepo->revoke($token);

        $newPlain = bin2hex(random_bytes(64));
        $newRefresh = RefreshToken::issue(
            userId: $token->userId(),
            deviceFingerprint: $token->deviceFingerprint(),
            rawToken: $newPlain,
            family: $token->family(),
            expiresAt: (new DateTimeImmutable())->modify("+{$this->refreshTtl} seconds")
        );

        $this->refreshRepo->save($newRefresh);

        return [
            'user_id'             => (string) $token->userId(),
            'access_token'        => $this->jwt->issueAccessToken(
                userId: (string) $token->userId(),
                deviceFingerprint: $token->deviceFingerprint()->toString(),
                jti: bin2hex(random_bytes(16))
            ),
            'token_type'          => 'Bearer',
            'expires_in'          => 900,
            'refresh_token'       => $newPlain,
            'refresh_expires_in'  => $this->refreshTtl,
        ];
    }

    public function logoutByRefreshToken(
        string $refreshToken,
        ?string $deviceId = null,
        ?string $userAgent = null,
        bool $revokeFamily = false
    ): void {
        $hash = hash('sha512', $refreshToken);
        $token = $this->refreshRepo->findByHash($hash);

        if (!$token) {
            return;
        }

        if ($revokeFamily) {
            $this->refreshRepo->revokeFamily($token->family()->toString());
        } else {
            $this->refreshRepo->revoke($token);
        }
    }

    public function logoutAllDevices(UserId $userId): void
    {
        $this->refreshRepo->revokeAllForUser($userId);
    }
}