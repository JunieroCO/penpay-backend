<?php
declare(strict_types=1);

namespace PenPay\Tests\Infrastructure\Repository\User;

use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\Aggregate\User;
use PenPay\Domain\User\Exception\UserNotFoundException;
use PenPay\Domain\User\ValueObject\{
    DerivLoginId,
    Email,
    PhoneNumber,
    PasswordHash,
    KycSnapshot
};
use PenPay\Infrastructure\Repository\User\UserRepository;
use PHPUnit\Framework\TestCase;
use PDO;

final class UserRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $repo;

    protected function setUp(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'],
            $_ENV['DB_DATABASE']
        );

        $this->pdo = new PDO(
            $dsn,
            $_ENV['DB_USERNAME'],
            $_ENV['DB_PASSWORD'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $this->verifyTestDatabase();
        $this->cleanDatabase();
        
        $this->repo = new UserRepository($this->pdo);
    }

    private function verifyTestDatabase(): void
    {
        $currentDb = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        
        if ($currentDb !== $_ENV['DB_DATABASE']) {
            $this->fail(sprintf(
                'DANGER: Not using test database! Current: %s, Expected: %s',
                $currentDb,
                $_ENV['DB_DATABASE']
            ));
        }
    }

    private function cleanDatabase(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
        $this->pdo->exec('TRUNCATE TABLE users');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
    }

    private function createTestKycSnapshot(): KycSnapshot
    {
        return KycSnapshot::fromArray([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone_number' => '+254712345678',
            'country_code' => 'KE',
            'date_of_birth' => time() - (25 * 365 * 24 * 60 * 60), // 25 years ago
            'place_of_birth' => 'KE',
            'residence' => 'KE',
        ]);
    }

    /** @test */
    public function it_saves_and_retrieves_user_by_id(): void
    {
        $user = User::register(
            UserId::generate(),
            Email::fromString('nashon@example.com'),
            PhoneNumber::fromE164('+254712345678'),
            DerivLoginId::fromString('CR1234567'),
            $this->createTestKycSnapshot(),
            PasswordHash::hash('Secret123!')
        );

        $this->repo->save($user);
        $fetched = $this->repo->getById($user->id());

        $this->assertSame((string) $user->id(), (string) $fetched->id());
        $this->assertSame('nashon@example.com', (string) $fetched->email());
        $this->assertSame('+254712345678', $fetched->phone()->toE164());
        $this->assertTrue($fetched->passwordHash()->verify('Secret123!'));
    }

    /** @test */
    public function it_throws_exception_when_user_not_found_by_id(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->repo->getById(UserId::generate());
    }

    /** @test */
    public function it_throws_exception_when_user_not_found_by_email(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->repo->getByEmail(Email::fromString('nonexistent@example.com'));
    }

    /** @test */
    public function it_throws_exception_when_user_not_found_by_phone(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->repo->getByPhone('+254799999999');
    }

    /** @test */
    public function it_throws_exception_when_user_not_found_by_deriv_login_id(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->repo->getByDerivLoginId('CR9999999');
    }

    /** @test */
    public function it_retrieves_user_by_email(): void
    {
        $user = User::register(
            UserId::generate(),
            Email::fromString('findme@example.com'),
            PhoneNumber::fromE164('+254700555666'),
            DerivLoginId::fromString('CR5556667'),
            $this->createTestKycSnapshot(),
            PasswordHash::hash('FindMe123!')
        );

        $this->repo->save($user);
        $fetched = $this->repo->getByEmail($user->email());

        $this->assertSame((string) $user->id(), (string) $fetched->id());
        $this->assertSame('findme@example.com', (string) $fetched->email());
    }

    /** @test */
    public function it_retrieves_user_by_phone(): void
    {
        $user = User::register(
            UserId::generate(),
            Email::fromString('phone@example.com'),
            PhoneNumber::fromE164('+254700555777'),
            DerivLoginId::fromString('CR5556778'),
            $this->createTestKycSnapshot(),
            PasswordHash::hash('Phone123!')
        );

        $this->repo->save($user);
        $fetched = $this->repo->getByPhone($user->phone()->toE164());

        $this->assertSame((string) $user->id(), (string) $fetched->id());
        $this->assertSame('+254700555777', $fetched->phone()->toE164());
    }

    /** @test */
    public function it_retrieves_user_by_deriv_login_id(): void
    {
        $user = User::register(
            UserId::generate(),
            Email::fromString('deriv@example.com'),
            PhoneNumber::fromE164('+254700555888'),
            DerivLoginId::fromString('CR5556889'),
            $this->createTestKycSnapshot(),
            PasswordHash::hash('Deriv123!')
        );

        $this->repo->save($user);
        $fetched = $this->repo->getByDerivLoginId((string) $user->derivLoginId());

        $this->assertSame((string) $user->id(), (string) $fetched->id());
        $this->assertSame('CR5556889', (string) $fetched->derivLoginId());
    }

    /** @test */
    public function it_checks_existence_by_email(): void
    {
        $user = User::register(
            UserId::generate(),
            Email::fromString('exists@example.com'),
            PhoneNumber::fromE164('+254700111222'),
            DerivLoginId::fromString('CR1112223'),
            $this->createTestKycSnapshot(),
            PasswordHash::hash('Exists123!')
        );

        $this->assertFalse($this->repo->existsByEmail($user->email()));
        
        $this->repo->save($user);
        
        $this->assertTrue($this->repo->existsByEmail($user->email()));
        $this->assertFalse($this->repo->existsByEmail(Email::fromString('notexists@example.com')));
    }

    /** @test */
    public function it_checks_existence_by_phone(): void
    {
        $user = User::register(
            UserId::generate(),
            Email::fromString('phone-exists@example.com'),
            PhoneNumber::fromE164('+254700222333'),
            DerivLoginId::fromString('CR2223334'),
            $this->createTestKycSnapshot(),
            PasswordHash::hash('PhoneExists123!')
        );

        $this->assertFalse($this->repo->existsByPhone($user->phone()->toE164()));
        
        $this->repo->save($user);
        
        $this->assertTrue($this->repo->existsByPhone($user->phone()->toE164()));
        $this->assertFalse($this->repo->existsByPhone('+254799888777'));
    }

    /** @test */
    public function it_checks_existence_by_deriv_login_id(): void
    {
        $user = User::register(
            UserId::generate(),
            Email::fromString('deriv-exists@example.com'),
            PhoneNumber::fromE164('+254700333444'),
            DerivLoginId::fromString('CR3334445'),
            $this->createTestKycSnapshot(),
            PasswordHash::hash('DerivExists123!')
        );

        $this->assertFalse($this->repo->existsByDerivLoginId((string) $user->derivLoginId()));
        
        $this->repo->save($user);
        
        $this->assertTrue($this->repo->existsByDerivLoginId((string) $user->derivLoginId()));
        $this->assertFalse($this->repo->existsByDerivLoginId('CR9999999'));
    }

    /** @test */
    public function it_updates_existing_user_fields(): void
    {
        $userId = UserId::generate();
        $originalEmail = Email::fromString('original@example.com');
        
        $user = User::register(
            $userId,
            $originalEmail,
            PhoneNumber::fromE164('+254711223344'),
            DerivLoginId::fromString('CR9988776'),
            $this->createTestKycSnapshot(),
            PasswordHash::hash('InitialPass!')
        );

        $this->repo->save($user);

        // Simulate user update with new phone and password
        $updated = User::register(
            $userId,
            $originalEmail,
            PhoneNumber::fromE164('+254711223355'),
            DerivLoginId::fromString('CR9988776'),
            $this->createTestKycSnapshot(),
            PasswordHash::hash('UpdatedPass!')
        );

        $this->repo->save($updated);
        $fetched = $this->repo->getById($userId);

        $this->assertSame((string) $userId, (string) $fetched->id());
        $this->assertSame('+254711223355', $fetched->phone()->toE164());
        $this->assertTrue($fetched->passwordHash()->verify('UpdatedPass!'));
        $this->assertFalse($fetched->passwordHash()->verify('InitialPass!'));
    }

    /** @test */
    public function it_handles_virtual_deriv_accounts_correctly(): void
    {
        $virtualLoginId = DerivLoginId::fromString('VRTC1234567');
        
        $user = User::register(
            UserId::generate(),
            Email::fromString('virtual@example.com'),
            PhoneNumber::fromE164('+254700444555'),
            $virtualLoginId,
            $this->createTestKycSnapshot(),
            PasswordHash::hash('Virtual123!')
        );

        $this->repo->save($user);

        $stmt = $this->pdo->prepare('SELECT is_virtual FROM users WHERE uuid = ?');
        $stmt->execute([(string) $user->id()]);
        $isVirtual = $stmt->fetchColumn();

        $this->assertSame(1, (int) $isVirtual);
    }

    /** @test */
    public function it_handles_real_deriv_accounts_correctly(): void
    {
        $realLoginId = DerivLoginId::fromString('CR1234567');
        
        $user = User::register(
            UserId::generate(),
            Email::fromString('real@example.com'),
            PhoneNumber::fromE164('+254700555666'),
            $realLoginId,
            $this->createTestKycSnapshot(),
            PasswordHash::hash('Real123!')
        );

        $this->repo->save($user);

        $stmt = $this->pdo->prepare('SELECT is_virtual FROM users WHERE uuid = ?');
        $stmt->execute([(string) $user->id()]);
        $isVirtual = $stmt->fetchColumn();

        $this->assertSame(0, (int) $isVirtual);
    }
}