<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\User;

use PDO;

final class UserTableRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function upsert(array $payload): void
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
            ':uuid' => $payload['uuid'],
            ':email' => $payload['email'],
            ':phone_e164' => $payload['phone_e164'],
            ':deriv_login_id' => $payload['deriv_login_id'],  
            ':password_hash' => $payload['password_hash'],
            ':is_virtual' => $payload['is_virtual'],
            ':preferred_language' => $payload['preferred_language'] ?? 'en_US',
            ':now' => $now,
        ]);
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE uuid = :uuid');
        $stmt->execute([':uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByPhone(string $phone): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE phone_e164 = :phone LIMIT 1');
        $stmt->execute([':phone' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByLoginId(string $loginId): ?array
    {
        // Change 'deriv_user_id' to 'deriv_login_id' if that's your column name
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE deriv_login_id = :loginId LIMIT 1');
        $stmt->execute([':loginId' => $loginId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function existsByPhone(string $phone): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE phone_e164 = :phone LIMIT 1');
        $stmt->execute([':phone' => $phone]);
        return (bool) $stmt->fetchColumn();
    }

    public function existsByEmail(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        return (bool) $stmt->fetchColumn();
    }

    public function existsByDerivLoginId(string $loginId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE deriv_login_id = :loginId LIMIT 1');
        $stmt->execute([':loginId' => $loginId]);
        return (bool) $stmt->fetchColumn();
    }
}