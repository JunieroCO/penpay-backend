<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\Payments\Transaction;

use PDO;
use Throwable;
use PenPay\Domain\Shared\Contracts\DomainEventPublisher;
use PenPay\Domain\Payments\Aggregate\Transaction;

final class TransactionWriteRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TransactionSerializer $serializer,
        private readonly IdempotencyKeyHasher $hasher,
        private readonly ?DomainEventPublisher $eventPublisher = null
    ) {}

    public function save(Transaction $transaction): void
    {
        $this->pdo->beginTransaction();

        try {
            /**
             * 1) serialize transaction row
             */
            $txRow = $this->serializer->toTransactionRow($transaction);
            $txRow['idempotency_key_hash'] = $this->hasher->hash($transaction->idempotencyKey());

            $sql = <<<SQL
INSERT INTO transactions
  (id, user_id, type, state,
   amount_usd_cents, amount_kes_cents,
   fx_rate, fx_rate_source, fx_rate_timestamp,
   idempotency_key_hash, user_deriv_login_id, withdrawal_verification_code,
   failure_reason, retry_count,
   created_at, completed_at, failed_at)
VALUES
  (:id, :user_id, :type, :state,
   :amount_usd_cents, :amount_kes_cents,
   :fx_rate, :fx_rate_source, :fx_rate_timestamp,
   :idempotency_key_hash, :user_deriv_login_id, :withdrawal_verification_code,
   :failure_reason, :retry_count,
   :created_at, :completed_at, :failed_at)
ON DUPLICATE KEY UPDATE
  state                    = VALUES(state),
  amount_usd_cents         = VALUES(amount_usd_cents),
  amount_kes_cents        = VALUES(amount_kes_cents),
  fx_rate                  = VALUES(fx_rate),
  fx_rate_source           = VALUES(fx_rate_source),
  fx_rate_timestamp        = VALUES(fx_rate_timestamp),
  user_deriv_login_id     = VALUES(user_deriv_login_id),
  withdrawal_verification_code = VALUES(withdrawal_verification_code),
  failure_reason           = VALUES(failure_reason),
  retry_count              = VALUES(retry_count),
  completed_at             = COALESCE(VALUES(completed_at), completed_at),
  failed_at                = COALESCE(VALUES(failed_at), failed_at)
SQL;

            // Check for duplicate idempotency key with different transaction ID
            $existingId = $this->pdo->prepare('SELECT id FROM transactions WHERE idempotency_key_hash = :hash AND id != :id LIMIT 1');
            $existingId->execute([
                ':hash' => $txRow['idempotency_key_hash'],
                ':id' => $txRow['id']
            ]);
            $existing = $existingId->fetchColumn();
            if ($existing !== false) {
                throw new \PDOException(
                    "Duplicate idempotency key: transaction {$txRow['id']} conflicts with existing transaction {$existing}",
                    23000
                );
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':id' => $txRow['id'],
                ':user_id' => $txRow['user_id'],
                ':type' => $txRow['type'],
                ':state' => $txRow['state'],
                ':amount_usd_cents' => $txRow['amount_usd_cents'],
                ':amount_kes_cents' => $txRow['amount_kes_cents'],
                ':fx_rate' => $txRow['fx_rate'],
                ':fx_rate_source' => $txRow['fx_rate_source'],
                ':fx_rate_timestamp' => $txRow['fx_rate_timestamp'],
                ':idempotency_key_hash' => $txRow['idempotency_key_hash'],
                ':user_deriv_login_id' => $txRow['user_deriv_login_id'],
                ':withdrawal_verification_code' => $txRow['withdrawal_verification_code'],
                ':failure_reason' => $txRow['failure_reason'],
                ':retry_count' => $txRow['retry_count'],
                ':created_at' => $txRow['created_at'],
                ':completed_at' => $txRow['completed_at'],
                ':failed_at' => $txRow['failed_at'],
            ]);

            /**
             * 2) M-Pesa Request (callback)
             */
            $mpesaRow = $this->serializer->toMpesaRequestRow($transaction);
            if ($mpesaRow !== null) {
                $this->pdo->prepare('DELETE FROM mpesa_requests WHERE transaction_id = :tx')
                    ->execute([':tx' => $mpesaRow['transaction_id']]);

                $this->pdo->prepare(<<<SQL
INSERT INTO mpesa_requests
  (transaction_id, mpesa_receipt_number, phone_number, amount_kes_cents, transaction_date, raw_payload, received_at)
VALUES
  (:transaction_id, :mpesa_receipt_number, :phone_number, :amount_kes_cents, :transaction_date, :raw_payload, :received_at)
SQL
                )->execute($mpesaRow);
            }

            /**
             * 3) Deriv transfer (deposit)
             */
            $derivRow = $this->serializer->toDerivTransferRow($transaction);
            if ($derivRow !== null) {
                $this->pdo->prepare('DELETE FROM deriv_transfers WHERE transaction_id = :tx')
                    ->execute([':tx' => $derivRow['transaction_id']]);

                $this->pdo->prepare(<<<SQL
INSERT INTO deriv_transfers
 (transaction_id, deriv_transfer_id, from_login_id, to_login_id, amount_usd_cents, executed_at, raw_payload)
VALUES
 (:transaction_id, :deriv_transfer_id, :from_login_id, :to_login_id, :amount_usd_cents, :executed_at, :raw_payload)
SQL
                )->execute($derivRow);
            }

            /**
             * 4) M-Pesa Disbursement (withdrawals)
             */
            $disRow = $this->serializer->toMpesaDisbursementRow($transaction);
            if ($disRow !== null) {
                $this->pdo->prepare('DELETE FROM mpesa_disbursements WHERE transaction_id = :tx')
                    ->execute([':tx' => $disRow['transaction_id']]);

                $this->pdo->prepare(<<<SQL
INSERT INTO mpesa_disbursements
 (transaction_id, conversation_id, originator_conversation_id, phone_number, amount_kes_cents, status,
  result_code, result_description, mpesa_receipt_number, raw_payload, initiated_at, completed_at)
VALUES
 (:transaction_id, :conversation_id, :originator_conversation_id, :phone_number, :amount_kes_cents, :status,
  :result_code, :result_description, :mpesa_receipt_number, :raw_payload, :initiated_at, :completed_at)
SQL
                )->execute($disRow);
            }

            /**
             * 5) Outbox Pattern (domain events table)
             */
            $events = $transaction->releaseEvents();
            if (!empty($events)) {
                $outboxStmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO domain_events (event_id, aggregate_id, aggregate_type, event_type, payload, occurred_at, published_at)
VALUES (:event_id, :aggregate_id, :aggregate_type, :event_type, :payload, :occurred_at, NULL)
SQL
                );

                foreach ($events as $evt) {
                    $outboxStmt->execute([
                        ':event_id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
                        ':aggregate_id' => (string)$transaction->id(),
                        ':aggregate_type' => 'transaction',
                        ':event_type' => $evt::class,
                        ':payload' => json_encode($evt, JSON_THROW_ON_ERROR),
                        ':occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                    ]);
                }

                /**
                 * 6) Optional synchronous event publishing
                 */
                if ($this->eventPublisher !== null) {
                    $this->eventPublisher->publish($events);
                }
            }

            $this->pdo->commit();

        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}