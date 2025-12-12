<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\Payments\Transaction;

use DateTimeImmutable;
use PDO;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;

final class TransactionReadRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TransactionRowMapper $mapper
    ) {}

    public function findById(TransactionId $id): ?Transaction
        {
            $sql = <<<SQL
    SELECT t.*,
        mr.mpesa_receipt_number AS mpesa_receipt_number,
        mr.phone_number AS mpesa_phone_number,
        mr.amount_kes_cents AS mpesa_amount_kes_cents,
        mr.transaction_date AS mpesa_transaction_date,
        mr.raw_payload AS mpesa_raw_payload,

        dt.deriv_transfer_id AS deriv_transfer_id,
        dt.from_login_id AS deriv_from_login_id,
        dt.to_login_id AS deriv_to_login_id,
        dt.amount_usd_cents AS deriv_amount_usd_cents,
        dt.executed_at AS deriv_executed_at,
        dt.raw_payload AS deriv_raw_payload,

        md.conversation_id AS disb_conversation_id,
        md.originator_conversation_id AS disb_originator_conversation_id,
        md.phone_number AS disb_phone_number,
        md.amount_kes_cents AS disb_amount_kes_cents,
        md.status AS disb_status,
        md.result_code AS disb_result_code,
        md.result_description AS disb_result_description,
        md.mpesa_receipt_number AS disb_mpesa_receipt_number,
        md.raw_payload AS disb_raw_payload,
        md.completed_at AS disb_completed_at
    FROM transactions t
    LEFT JOIN mpesa_requests mr ON mr.transaction_id = t.id
    LEFT JOIN deriv_transfers dt ON dt.transaction_id = t.id
    LEFT JOIN mpesa_disbursements md ON md.transaction_id = t.id
    WHERE t.id = :id
    LIMIT 1
    SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => (string)$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapper->fromRow($row);
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?Transaction
    {
        $hash = hash('sha256', (string)$key);

        $stmt = $this->pdo->prepare('SELECT id FROM transactions WHERE idempotency_key_hash = :h LIMIT 1');
        $stmt->execute([':h' => $hash]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            return null;
        }

        return $this->findById(TransactionId::fromString($id));
    }

    public function existsByIdempotencyKey(IdempotencyKey $key): bool
    {
        $hash = hash('sha256', (string)$key);
        $stmt = $this->pdo->prepare('SELECT 1 FROM transactions WHERE idempotency_key_hash = :h LIMIT 1');
        $stmt->execute([':h' => $hash]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return Transaction[] */
    public function findByUserId(string $userId, ?int $limit = 50, ?int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM transactions WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $r) => $this->mapper->fromRow($r), $rows);
    }

    /** @param TransactionStatus[] $statuses @return Transaction[] */
    public function findByStatus(array $statuses, ?int $limit = 100): array
    {
        $values = array_map(fn($s) => $s->value, $statuses);
        $in = implode(',', array_fill(0, count($values), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT * FROM transactions WHERE state IN ($in) ORDER BY created_at DESC LIMIT ?"
        );

        $params = [...$values, $limit];
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $r) => $this->mapper->fromRow($r), $rows);
    }

    public function getUserDailyTotal(string $userId, DateTimeImmutable $date): int
    {
        $start = $date->setTime(0, 0, 0);
        $end   = $date->setTime(23, 59, 59);

        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount_usd_cents), 0) AS total
             FROM transactions
             WHERE user_id = :uid
               AND created_at BETWEEN :start AND :end
               AND state IN ('COMPLETED','PROCESSING')"
        );

        $stmt->execute([
            ':uid' => $userId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);

        return (int)$stmt->fetchColumn();
    }

    public function countUserTransactionsForDate(string $userId, DateTimeImmutable $date): int
    {
        $start = $date->setTime(0, 0, 0);
        $end   = $date->setTime(23, 59, 59);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM transactions
             WHERE user_id = :uid
               AND created_at BETWEEN :start AND :end
               AND state IN ('COMPLETED','PROCESSING')"
        );

        $stmt->execute([
            ':uid' => $userId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);

        return (int)$stmt->fetchColumn();
    }

    /** @return Transaction[] */
    public function findPendingRetry(int $maxRetries = 3, int $olderThanMinutes = 5): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM transactions
             WHERE state = 'FAILED'
               AND retry_count < :max
               AND failed_at <= (NOW() - INTERVAL :mins MINUTE)
             ORDER BY failed_at ASC"
        );

        $stmt->execute([':max' => $maxRetries, ':mins' => $olderThanMinutes]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->mapper->fromRow($r), $rows);
    }

    /** @return Transaction[] */
    public function findAwaitingConfirmation(int $olderThanMinutes = 2, ?int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM transactions
             WHERE state IN ('AWAITING_MPESA_CALLBACK','AWAITING_DERIV_CONFIRMATION')
               AND created_at <= (NOW() - INTERVAL :mins MINUTE)
             ORDER BY created_at ASC
             LIMIT :lim"
        );

        $stmt->bindValue(':mins', $olderThanMinutes, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $r) => $this->mapper->fromRow($r), $rows);
    }
}