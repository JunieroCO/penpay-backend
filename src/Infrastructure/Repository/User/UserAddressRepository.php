<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\User;

use PDO;

final class UserAddressRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function saveAddress(string $uuid, array $address): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO user_address (user_id, address_line_1, address_line_2, city, state, postcode, updated_at)
VALUES (:user_id, :l1, :l2, :city, :state, :postcode, :now)
ON DUPLICATE KEY UPDATE
  address_line_1 = VALUES(address_line_1),
  address_line_2 = VALUES(address_line_2),
  city = VALUES(city),
  state = VALUES(state),
  postcode = VALUES(postcode),
  updated_at = VALUES(updated_at)
SQL
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt->execute([
            ':user_id' => $uuid,
            ':l1' => $address['address_line_1'] ?? null,
            ':l2' => $address['address_line_2'] ?? null,
            ':city' => $address['city'] ?? null,
            ':state' => $address['state'] ?? null,
            ':postcode' => $address['postcode'] ?? null,
            ':now' => $now,
        ]);
    }

    public function findByUserId(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_address WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}