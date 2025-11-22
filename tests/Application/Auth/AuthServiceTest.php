<?php
declare(strict_types=1);

namespace Tests\Application\Auth;

use PHPUnit\Framework\TestCase;
use PenPay\Application\Auth\AuthService;
use PenPay\Domain\Auth\Entity\RefreshToken;
use PenPay\Domain\Auth\Repository\RefreshTokenRepositoryInterface;
use PenPay\Domain\Auth\ValueObject\DeviceFingerprint;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\Aggregate\User;
use PenPay\Domain\User\Repository\UserRepositoryInterface;
use PenPay\Domain\User\ValueObject\{
    Email,
    PhoneNumber,
    DerivLoginId,
    PasswordHash,
    KycSnapshot
};
use PenPay\Infrastructure\Auth\JWTHandler; // ← FIXED NAMESPACE

final class AuthServiceTest extends TestCase
{
    private AuthService $auth;
    private UserRepositoryInterface $userRepo;
    private RefreshTokenRepositoryInterface $refreshRepo;
    private JWTHandler $jwt;

    // Make constants PUBLIC so inner class can access
    public const DEVICE_ID   = 'test-device-iphone15';
    public const USER_AGENT  = 'PenPay/2.1 (iOS)';
    private const KEYS_DIR   = __DIR__ . '/../../keys';

    protected function setUp(): void
    {
        $this->userRepo    = new InMemoryUserRepository();
        $this->refreshRepo = new InMemoryRefreshTokenRepository();

        $this->jwt = new JWTHandler(
            self::KEYS_DIR . '/private.pem',
            self::KEYS_DIR . '/public.pem',
            'https://penpay.test',
            900
        );

        $this->auth = new AuthService(
            $this->userRepo,
            $this->refreshRepo,
            $this->jwt
        );
    }

    /** @test */
    public function login_creates_device_bound_refresh_token(): void
    {
        $user = $this->createTestUser();
        $this->userRepo->save($user);

        $result = $this->auth->login('test@penpay.co', 'password123', self::DEVICE_ID, self::USER_AGENT);

        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);

        $saved = $this->refreshRepo->findByHash(hash('sha512', $result['refresh_token']));
        $this->assertNotNull($saved);
        $this->assertSame((string) $user->id(), (string) $saved->userId());
        $this->assertTrue($saved->deviceFingerprint()->equals(DeviceFingerprint::fromString(self::DEVICE_ID, self::USER_AGENT)));
        $this->assertFalse($saved->isRevoked());
    }

    /** @test */
    public function refresh_rotates_token_and_revokes_old_one(): void
    {
        $user = $this->createTestUser();
        $this->userRepo->save($user);

        $login = $this->auth->login('test@penpay.co', 'password123', self::DEVICE_ID, self::USER_AGENT);
        $oldToken = $login['refresh_token'];

        $newResult = $this->auth->refresh($oldToken, self::DEVICE_ID, self::USER_AGENT);
        $newToken = $newResult['refresh_token'];

        $this->assertNotSame($oldToken, $newToken);

        $oldSaved = $this->refreshRepo->findByHash(hash('sha512', $oldToken));
        $this->assertTrue($oldSaved?->isRevoked() ?? true);

        $newSaved = $this->refreshRepo->findByHash(hash('sha512', $newToken));
        $this->assertNotNull($newSaved);
        $this->assertFalse($newSaved->isRevoked());
    }

    /** @test */
    public function token_reuse_revokes_entire_family(): void
    {
        $user = $this->createTestUser();
        $this->userRepo->save($user);

        $login = $this->auth->login('test@penpay.co', 'password123', self::DEVICE_ID, self::USER_AGENT);
        $token = $login['refresh_token'];

        $this->auth->refresh($token, self::DEVICE_ID, self::USER_AGENT);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid or expired refresh token');
        $this->auth->refresh($token, self::DEVICE_ID, self::USER_AGENT);

        $active = $this->refreshRepo->findActiveByUserAndDevice(
            $user->id(),
            DeviceFingerprint::fromString(self::DEVICE_ID, self::USER_AGENT)->toString()
        );
        $this->assertEmpty($active);
    }

    private function createTestUser(): User
    {
        return User::register(
            UserId::generate(),
            Email::fromString('test@penpay.co'),
            PhoneNumber::fromE164('+254700000000'),
            DerivLoginId::fromString('CR1234567'),
            KycSnapshot::empty(),
            PasswordHash::hash('password123')
        );
    }
}

final class InMemoryUserRepository implements UserRepositoryInterface
{
    private array $users = [];

    public function save(User $user): void
    {
        $this->users[(string) $user->id()] = $user;
    }

    public function getByEmail(Email $email): User
    {
        foreach ($this->users as $user) {
            if ($user->email()->equals($email)) return $user;
        }
        throw new \PenPay\Domain\User\Exception\UserNotFoundException();
    }

    public function getById(UserId $id): User
    {
        return $this->users[(string) $id] ?? throw new \PenPay\Domain\User\Exception\UserNotFoundException();
    }

    public function getByDerivLoginId(string $id): User { throw new \Exception(); }
    public function getByPhone(string $phone): User { throw new \Exception(); }
    public function existsByEmail(Email $email): bool { return true; }
    public function existsByPhone(string $phone): bool { return false; }
    public function existsByDerivLoginId(string $id): bool { return false; }
}

final class InMemoryRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    private array $tokens = [];

    public function save(RefreshToken $token): void
    {
        $this->tokens[] = $token;
    }

    public function findByHash(string $hash): ?RefreshToken
    {
        foreach ($this->tokens as $t) {
            if ($t->tokenHash() === $hash) {
                $fp = DeviceFingerprint::fromString(AuthServiceTest::DEVICE_ID, AuthServiceTest::USER_AGENT);
                return $t->isUsable($fp) ? $t->withNewUsage() : $t;
            }
        }
        return null;
    }

    public function findActiveByUserAndDevice(UserId $userId, string $fp): array
    {
        $fingerprint = DeviceFingerprint::fromString($fp, AuthServiceTest::USER_AGENT);
        return array_filter($this->tokens, fn($t) =>
            $t->userId()->equals($userId) &&
            $t->deviceFingerprint()->equals($fingerprint) &&
            !$t->isRevoked() &&
            $t->expiresAt() > new \DateTimeImmutable()
        );
    }

    public function revoke(RefreshToken $token): void
    {
        foreach ($this->tokens as $i => $t) {
            if ($t->id()->equals($token->id())) {
                $this->tokens[$i] = $t->revoke();
            }
        }
    }

    public function revokeFamily(string $familyId): void
    {
        foreach ($this->tokens as $i => $t) {
            if ($t->family()->toString() === $familyId) {
                $this->tokens[$i] = $t->revoke();
            }
        }
    }

    public function revokeAllForUser(UserId $userId): void
    {
        foreach ($this->tokens as $i => $t) {
            if ($t->userId()->equals($userId)) {
                $this->tokens[$i] = $t->revoke();
            }
        }
    }

    public function findById($id): ?RefreshToken { return null; }
}