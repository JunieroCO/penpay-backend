<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\User;

use PDO;

final class UserComplianceRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function saveCompliance(string $uuid, array $data): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO user_compliance (user_id, employment_status, account_opening_reason, fatca_declaration, non_pep_declaration, tin_number, tax_residence, tnc_status, updated_at)
VALUES (:user_id, :employment_status, :account_opening_reason, :fatca_declaration, :non_pep_declaration, :tin_number, :tax_residence, :tnc_status, :now)
ON DUPLICATE KEY UPDATE
  employment_status = VALUES(employment_status),
  account_opening_reason = VALUES(account_opening_reason),
  fatca_declaration = VALUES(fatca_declaration),
  non_pep_declaration = VALUES(non_pep_declaration),
  tin_number = VALUES(tin_number),
  tax_residence = VALUES(tax_residence),
  tnc_status = VALUES(tnc_status),
  updated_at = VALUES(updated_at)
SQL
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt->execute([
            ':user_id' => $uuid,
            ':employment_status' => $data['employment_status'] ?? null,
            ':account_opening_reason' => $data['account_opening_reason'] ?? null,
            ':fatca_declaration' => $data['fatca_declaration'] ?? 0,
            ':non_pep_declaration' => $data['non_pep_declaration'] ?? 0,
            ':tin_number' => $data['tin_number'] ?? null,
            ':tax_residence' => $data['tax_residence'] ?? null,
            ':tnc_status' => json_encode($data['tnc_status'] ?? []),
            ':now' => $now,
        ]);
    }

    public function findByUserId(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_compliance WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}