<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\User;

use PDO;

final class UserPhoneVerificationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function savePhoneVerification(string $uuid, array $payload): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO user_phone_verification (user_id, phone, verified, last_verified_at, updated_at)
VALUES (:user_id, :phone, :verified, :last_verified_at, :now)
ON DUPLICATE KEY UPDATE
  phone = VALUES(phone),
  verified = VALUES(verified),
  last_verified_at = VALUES(last_verified_at),
  updated_at = VALUES(updated_at)
SQL
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt->execute([
            ':user_id' => $uuid,
            ':phone' => $payload['phone'] ?? null,
            ':verified' => $payload['verified'] ? 1 : 0,
            ':last_verified_at' => $payload['last_verified_at'],
            ':now' => $now,
        ]);
    }

    public function findByUserId(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_phone_verification WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}