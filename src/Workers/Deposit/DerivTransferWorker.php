<?php
declare(strict_types=1);

namespace PenPay\Workers\Deposit;

use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Entity\DerivTransfer;
use PenPay\Domain\Payments\Entity\DerivResult; 
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Infrastructure\Deriv\Deposit\DerivDepositGatewayInterface;
use PenPay\Infrastructure\Deriv\DerivConfig;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Throwable;
use DateTimeImmutable;

final class DerivTransferWorker
{
    private const MAX_RETRIES = 3;
    private const BASE_BACKOFF_USEC = 200_000; // 200ms

    public function __construct(
        private readonly TransactionRepositoryInterface $txRepo,
        private readonly DerivDepositGatewayInterface $derivGateway,
        private readonly RedisStreamPublisherInterface $publisher,
        private readonly LoggerInterface $logger,
        private readonly LoopInterface $loop,
        private readonly DerivConfig $derivConfig,
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

        try {
            $transaction = $this->txRepo->getById($txId);
        } catch (Throwable $e) {
            $this->logger->error('DerivTransferWorker: failed to load transaction', [
                'transaction_id' => (string)$txId,
                'error' => $e->getMessage()
            ]);
            return;
        }

        // === IDEMPOTENCY: Final states are terminal ===
        if ($transaction->isFinalized()) {
            $this->logger->info('DerivTransferWorker: already finalized — skipping', [
                'transaction_id' => (string)$txId,
                'status' => $transaction->status()->value,
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

        // === GUARD: Must have Deriv login ID ===
        if (!$transaction->hasDerivLoginId()) {
            $this->failTransaction(
                $transaction,
                'missing_deriv_data',
                'User has no Deriv account'
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
                    'amount_usd_cents' => $transaction->amountUsd()->cents,
                    'login_id' => $transaction->userDerivLoginId(),
                ]);

                $paymentAgentToken = $this->derivConfig->agentToken();
                
                if (empty($paymentAgentToken)) {
                    throw new \RuntimeException('Payment agent token not configured');
                }

                $promise = $this->derivGateway->deposit(
                    loginId: $transaction->userDerivLoginId(),
                    amountUsd: $transaction->amountUsd()->toDecimal(), 
                    paymentAgentToken: $paymentAgentToken,
                    reference: (string)$txId,
                    metadata: [
                        'mpesa_receipt' => $payload['mpesa_receipt'] ?? null,
                        'phone' => $payload['phone'] ?? null,
                    ]
                );

                // Wait for promise to resolve
                $result = null;
                $error = null;

                $promise->then(
                    function (DerivResult $res) use (&$result) { // Changed type hint
                        $result = $res;
                        $this->loop->stop();
                    },
                    function (Throwable $e) use (&$error) {
                        $error = $e;
                        $this->loop->stop();
                    }
                );

                $this->loop->run();

                if ($error !== null) {
                    throw $error;
                }

                if ($result === null) {
                    throw new \RuntimeException('No result received from Deriv gateway');
                }

                if ($result->isSuccess()) {
                    $this->completeTransaction($transaction, $result, $payload);
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

    private function completeTransaction(Transaction $tx, DerivResult $result, array $payload): void // Changed type hint
    {
        try {
            // Get payment agent login ID from config
            $paymentAgentLoginId = $this->derivConfig->defaultLoginId();
            
            if (empty($paymentAgentLoginId)) {
                throw new \RuntimeException('Payment agent login ID not configured');
            }

            $derivTransfer = DerivTransfer::forDeposit(
                transactionId: $tx->id(),
                paymentAgentLoginId: $paymentAgentLoginId,
                userDerivLoginId: $tx->userDerivLoginId(),
                amountUsd: $result->amountUsd(), // Directly use Money object from result
                derivTransferId: $result->transferId(),
                derivTxnId: $result->txnId(),
                executedAt: new DateTimeImmutable(),
                rawResponse: $result->raw()
            );

            $tx->recordDerivTransfer($derivTransfer);
            $this->txRepo->save($tx);

            $this->publisher->publish('deposits.completed', [
                'transaction_id' => (string)$tx->id(),
                'deriv_txn_id' => $result->txnId(),
                'deriv_transfer_id' => $result->transferId(),
                'amount_usd_cents' => $tx->amountUsd()->cents,
                'mpesa_receipt' => $tx->mpesaRequest()?->mpesaReceiptNumber ?? '',
                'completed_at' => (new DateTimeImmutable())->format('c'),
            ]);

            $this->logger->info('DerivTransferWorker: deposit completed', [
                'transaction_id' => (string)$tx->id(),
                'deriv_txn_id' => $result->txnId(),
            ]);
        } catch (Throwable $e) {
            $this->logger->critical('DerivTransferWorker: failed to persist completion', [
                'transaction_id' => (string)$tx->id(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function failTransaction(Transaction $tx, string $reason, string $message): void
    {
        try {
            $tx->fail($reason, $message);

            $this->txRepo->save($tx);

            $this->publisher->publish('deposits.failed', [
                'transaction_id' => (string)$tx->id(),
                'reason' => $reason,
                'message' => $message,
                'failed_at' => (new DateTimeImmutable())->format('c'),
            ]);

            $this->logger->warning('DerivTransferWorker: deposit failed', [
                'transaction_id' => (string)$tx->id(),
                'reason' => $reason,
                'message' => $message,
            ]);
        } catch (Throwable $e) {
            $this->logger->critical('DerivTransferWorker: failed to persist failure', [
                'transaction_id' => (string)$tx->id(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}