<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\User;

use PDO;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\Aggregate\User;
use PenPay\Domain\User\Repository\UserRepositoryInterface;
use PenPay\Domain\User\Exception\UserNotFoundException;
use PenPay\Domain\User\ValueObject\Email;
use PenPay\Domain\User\Factory\UserFactory;

final class UserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(User $user): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO users (uuid, email, phone_e164, deriv_login_id, password_hash, is_virtual, preferred_language, created_at, updated_at)
            VALUES (:uuid, :email, :phone_e164, :deriv_login_id, :password_hash, :is_virtual, :preferred_language, :now, :now)
            ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                phone_e164 = VALUES(phone_e164),
                deriv_login_id = VALUES(deriv_login_id),  
                password_hash = VALUES(password_hash),
                is_virtual = VALUES(is_virtual),
                preferred_language = VALUES(preferred_language),
                updated_at = VALUES(updated_at)
            SQL
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt->execute([
            ':uuid' => (string) $user->id(),
            ':email' => (string) $user->email(),
            ':phone_e164' => $user->phone()->toE164(),
            ':deriv_login_id' => (string) $user->derivLoginId(),
            ':password_hash' => $user->passwordHash()->toString(),
            ':is_virtual' => $user->derivLoginId()->isVirtual() ? 1 : 0,
            ':preferred_language' => 'en_US',
            ':now' => $now,
        ]);
    }

    public function getById(UserId $id): User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE uuid = :uuid');
        $stmt->execute([':uuid' => (string) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $this->reconstitute($row ?: null, UserNotFoundException::withId((string) $id));
    }

    public function getByEmail(Email $email): User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute([':email' => (string) $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $this->reconstitute($row ?: null, UserNotFoundException::withEmail((string) $email));
    }

    public function getByPhone(string $e164): User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE phone_e164 = :phone LIMIT 1');
        $stmt->execute([':phone' => $e164]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $this->reconstitute($row ?: null, UserNotFoundException::withPhone($e164));
    }

    public function getByDerivLoginId(string $loginId): User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE deriv_login_id = :loginId LIMIT 1');
        $stmt->execute([':loginId' => $loginId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $this->reconstitute($row ?: null, UserNotFoundException::withDerivLoginId($loginId));
    }

    public function existsByEmail(Email $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => (string) $email]);
        return (bool) $stmt->fetchColumn();
    }

    public function existsByPhone(string $e164): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE phone_e164 = :phone LIMIT 1');
        $stmt->execute([':phone' => $e164]);
        return (bool) $stmt->fetchColumn();
    }

    public function existsByDerivLoginId(string $loginId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE deriv_login_id = :loginId LIMIT 1');
        $stmt->execute([':loginId' => $loginId]);
        return (bool) $stmt->fetchColumn();
    }

    private function reconstitute(?array $row, \Throwable $notFound): User
    {
        if (!$row) {
            throw $notFound;
        }

        return UserFactory::reconstitute([
            'users' => $row,
            'profile' => [],
            'address' => [],
            'compliance' => [],
            'phone_verification' => [],
            'devices' => [],
        ]);
    }
}