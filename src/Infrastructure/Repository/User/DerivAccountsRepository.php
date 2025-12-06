<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\User;

use PDO;

final class DerivAccountsRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function upsertDerivAccount(string $uuid, array $payload): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO deriv_accounts (uuid, user_id, loginid, landing_company, landing_company_name, is_virtual, currency, balance_usd_cents, created_at, updated_at)
VALUES (:uuid, :user_id, :loginid, :landing_company, :landing_company_name, :is_virtual, :currency, :balance_usd_cents, :now, :now)
ON DUPLICATE KEY UPDATE
  loginid = VALUES(loginid),
  landing_company = VALUES(landing_company),
  landing_company_name = VALUES(landing_company_name),
  is_virtual = VALUES(is_virtual),
  currency = VALUES(currency),
  updated_at = VALUES(updated_at)
SQL
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt->execute([
            ':uuid' => $payload['uuid'] ?? $this->generateUuidForDeriv($uuid),
            ':user_id' => $uuid,
            ':loginid' => $payload['loginid'],
            ':landing_company' => $payload['landing_company'] ?? null,
            ':landing_company_name' => $payload['landing_company_name'] ?? null,
            ':is_virtual' => $payload['is_virtual'] ?? 0,
            ':currency' => $payload['currency'] ?? 'USD',
            ':balance_usd_cents' => $payload['balance_usd_cents'] ?? 0,
            ':now' => $now,
        ]);
    }

    public function findByLoginId(string $loginId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM deriv_accounts WHERE loginid = :loginid LIMIT 1');
        $stmt->execute([':loginid' => $loginId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function existsByLoginId(string $loginId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM deriv_accounts WHERE loginid = :loginid LIMIT 1');
        $stmt->execute([':loginid' => $loginId]);
        return (bool)$stmt->fetchColumn();
    }

    private function generateUuidForDeriv(string $userUuid): string
    {
        // non-critical: derive a uuid for the deriv_accounts row
        return \Ramsey\Uuid\Uuid::uuid7()->toString();
    }
}