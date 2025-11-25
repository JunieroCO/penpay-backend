<?php
declare(strict_types=1);

namespace PenPay\Workers;

use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Entity\DerivTransfer;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Infrastructure\Deriv\DerivGatewayInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use DateTimeImmutable;

/**
 * DerivTransferWorker — Final & Eternal
 *
 * Consumes: deposits.mpesa_confirmed
 * Purpose:  Finalize deposit by crediting user's Deriv account via Payment Agent
 * Guarantees:
 *   • Idempotent
 *   • Money-safe
 *   • Observable
 *   • Resilient
 *   • Production-grade
 *
 * This is the last mile of African fintech.
 */
final class DerivTransferWorker
{
    private const MAX_RETRIES = 3;
    private const BASE_BACKOFF_USEC = 200_000; // 200ms

    public function __construct(
        private readonly TransactionRepositoryInterface $txRepo,
        private readonly DerivGatewayInterface $derivGateway,
        private readonly RedisStreamPublisherInterface $publisher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array{
     *     transaction_id: string,
     *     mpesa_receipt?: string,
     *     amount_kes_cents?: int,
     *     amount_usd?: float,
     *     phone?: string
     * } $payload
     */
    public function handle(array $payload): void
    {
        $txId = $this->extractTransactionId($payload);
        if (!$txId) {
            return;
        }

        $transaction = $this->txRepo->getById($txId);
        if (!$transaction) {
            $this->logger->error('DerivTransferWorker: transaction not found', [
                'transaction_id' => (string)$txId,
            ]);
            return;
        }

        // === IDEMPOTENCY: Final states are terminal ===
        if ($transaction->isFinalized()) {
            $this->logger->info('DerivTransferWorker: already finalized — skipping', [
                'transaction_id' => (string)$txId,
                'status' => $transaction->getStatus()->value,
            ]);
            return;
        }

        // === GUARD: Must have M-Pesa confirmation ===
        if (!$transaction->hasMpesaCallback()) {
            $this->logger->warning('DerivTransferWorker: no M-Pesa callback yet', [
                'transaction_id' => (string)$txId,
            ]);
            return;
        }

        // === GUARD: Must have Deriv credentials and USD amount ===
        if (!$transaction->hasDerivCredentials() || !$transaction->hasUsdAmount()) {
            $this->failTransaction(
                $transaction,
                'missing_deriv_data',
                'User has no Deriv account or USD amount not set'
            );
            return;
        }

        // === RETRY LOOP: Call Deriv Payment Agent ===
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $this->logger->info('DerivTransferWorker: calling Deriv payment_agent_deposit', [
                    'transaction_id' => (string)$txId,
                    'attempt' => $attempt,
                    'amount_usd' => $transaction->amountUsd(),
                    'login_id' => $transaction->userDerivLoginId(),
                ]);

                $result = $this->derivGateway->paymentAgentDeposit(
                    loginId: $transaction->userDerivLoginId(),
                    amountUsd: $transaction->amountUsd(),
                    paymentAgentToken: $transaction->paymentAgentToken(),
                    reference: (string)$txId,
                    metadata: [
                        'mpesa_receipt' => $payload['mpesa_receipt'] ?? null,
                        'phone' => $payload['phone'] ?? null,
                    ]
                );

                if ($result->isSuccess()) {
                    $this->completeTransaction($transaction, $result);
                    return;
                }

                $lastError = new \RuntimeException($result->errorMessage() ?? 'Deriv returned failure');
                throw $lastError;
            } catch (Throwable $e) {
                $lastError = $e;
                $this->logger->warning('DerivTransferWorker: attempt failed', [
                    'transaction_id' => (string)$txId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    usleep(self::BASE_BACKOFF_USEC * $attempt);
                    continue;
                }

                // Final failure
                $this->failTransaction(
                    $transaction,
                    'deriv_transfer_failed',
                    $e->getMessage()
                );
                return;
            }
        }
    }

    private function extractTransactionId(array $payload): ?TransactionId
    {
        $idStr = $payload['transaction_id'] ?? null;
        if (!is_string($idStr) || $idStr === '') {
            $this->logger->warning('DerivTransferWorker: missing or invalid transaction_id', $payload);
            return null;
        }

        try {
            return TransactionId::fromString($idStr);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('DerivTransferWorker: invalid transaction_id format', [
                'transaction_id' => $idStr,
            ]);
            return null;
        }
    }

    private function completeTransaction(Transaction $tx, object $result): void
    {
        try {
            $derivTransfer = DerivTransfer::success(
                transactionId: $tx->getId(),
                derivAccountId: $tx->userDerivLoginId(),
                amountUsd: Money::usd((int) round($result->amountUsd() * 100)),
                derivTransferId: $result->transferId(),
                derivTxnId: $result->txnId(),
                executedAt: new DateTimeImmutable(),
                rawResponse: $result->raw()
            );

            $tx->completeWithDerivTransfer($derivTransfer);
            $this->txRepo->save($tx);

            $this->publisher->publish('deposits.completed', [
                'transaction_id' => (string)$tx->getId(),
                'deriv_txn_id' => $result->txnId(),
                'deriv_transfer_id' => $result->transferId(),
                'amount_usd' => $tx->amountUsd(),
                'mpesa_receipt' => $tx->mpesaReceiptNumber(),
                'completed_at' => (new DateTimeImmutable())->format('c'),
            ]);

            $this->logger->info('DerivTransferWorker: deposit completed', [
                'transaction_id' => (string)$tx->getId(),
                'deriv_txn_id' => $result->txnId(),
            ]);
        } catch (Throwable $e) {
            $this->logger->critical('DerivTransferWorker: failed to persist completion', [
                'transaction_id' => (string)$tx->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function failTransaction(Transaction $tx, string $reason, string $message): void
    {
        try {
            $tx->fail($reason);

            $this->txRepo->save($tx);

            $this->publisher->publish('deposits.failed', [
                'transaction_id' => (string)$tx->getId(),
                'reason' => $reason,
                'message' => $message,
                'failed_at' => (new DateTimeImmutable())->format('c'),
            ]);

            $this->logger->warning('DerivTransferWorker: deposit failed', [
                'transaction_id' => (string)$tx->getId(),
                'reason' => $reason,
                'message' => $message,
            ]);
        } catch (Throwable $e) {
            $this->logger->critical('DerivTransferWorker: failed to persist failure', [
                'transaction_id' => (string)$tx->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}