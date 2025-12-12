<?php
declare(strict_types=1);

namespace PenPay\Workers\Withdrawal;

use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Entity\DerivTransfer;
use PenPay\Domain\Payments\Entity\DerivResult;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface; 
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Infrastructure\Deriv\DerivConfig;
use PenPay\Infrastructure\Deriv\Withdrawal\DerivWithdrawalGatewayInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use Psr\Log\LoggerInterface;
use DateTimeImmutable;
use RuntimeException;
use React\EventLoop\LoopInterface;

final class DerivDebitWorker
{
    private const MAX_RETRIES = 3;
    private const BASE_BACKOFF_USEC = 200_000; // 200ms

    public function __construct(
        private readonly TransactionRepositoryInterface $txRepo,
        private readonly DerivWithdrawalGatewayInterface $derivGateway,
        private readonly DerivConfig $derivConfig,
        private readonly OneTimeSecretStoreInterface $secretStore,
        private readonly RedisStreamPublisherInterface $publisher,
        private readonly LoggerInterface $logger,
        private readonly LoopInterface $loop
    ) {}

    /**
     * @param array{transaction_id: string, secret_key?: string, usd_cents?: int} $payload
     */
    public function handle(array $payload): void
    {
        $txId = $this->extractTransactionId($payload);
        if (!$txId) {
            return;
        }

        try {
            $transaction = $this->txRepo->getById($txId);
        } catch (\Throwable $e) {
            $this->logger->error('DerivDebitWorker: failed to load transaction', [
                'transaction_id' => (string)$txId,
                'error' => $e->getMessage()
            ]);
            return;
        }

        // === IDEMPOTENCY: Final states are terminal ===
        if ($transaction->isFinalized()) {
            $this->logger->info('DerivDebitWorker: already finalized — skipping', [
                'transaction_id' => (string)$txId,
                'status' => $transaction->status()->value,
            ]);
            return;
        }

        // === GUARD: Must be a withdrawal transaction ===
        if ($transaction->type() !== TransactionType::WITHDRAWAL) {
            $this->failTransaction(
                $transaction,
                'invalid_transaction_type',
                'Only withdrawal transactions can be processed by DerivDebitWorker'
            );
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

        // === GUARD: Must have verification code ===
        $verificationCode = $this->getVerificationCode($payload, $transaction);
        if ($verificationCode === null) {
            // Error already logged in getVerificationCode
            return;
        }

        // === RETRY LOOP: Call Deriv Payment Agent ===
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $this->logger->info('DerivDebitWorker: calling Deriv paymentagent_withdraw', [
                    'transaction_id' => (string)$txId,
                    'attempt' => $attempt,
                    'amount_usd_cents' => $transaction->amountUsd()->cents,
                    'login_id' => $transaction->userDerivLoginId(),
                ]);

                $paymentAgentLoginId = $this->derivConfig->defaultLoginId();
                
                if (empty($paymentAgentLoginId)) {
                    throw new RuntimeException('Payment agent login ID not configured');
                }

                $promise = $this->derivGateway->withdraw(
                    loginId: $transaction->userDerivLoginId(),
                    amountUsd: $transaction->amountUsd()->toDecimal(),
                    verificationCode: $verificationCode,
                    reference: (string)$txId
                );

                // Wait for promise to resolve
                $result = null;
                $error = null;

                $promise->then(
                    function (DerivResult $res) use (&$result) {
                        $result = $res;
                        $this->loop->stop();
                    },
                    function (\Throwable $e) use (&$error) {
                        $error = $e;
                        $this->loop->stop();
                    }
                );

                $this->loop->run();

                if ($error !== null) {
                    throw $error;
                }

                if ($result === null) {
                    throw new RuntimeException('No result received from Deriv gateway');
                }

                if ($result->isSuccess()) {
                    $this->completeTransaction($transaction, $result, $verificationCode);
                    return;
                }

                $lastError = new RuntimeException($result->errorMessage() ?? 'Deriv returned failure');
                throw $lastError;

            } catch (\Throwable $e) {
                $lastError = $e;
                $this->logger->warning('DerivDebitWorker: attempt failed', [
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
                    'deriv_withdrawal_failed',
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
            $this->logger->warning('DerivDebitWorker: missing or invalid transaction_id', $payload);
            return null;
        }

        try {
            return TransactionId::fromString($idStr);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('DerivDebitWorker: invalid transaction_id format', [
                'transaction_id' => $idStr,
            ]);
            return null;
        }
    }

    private function getVerificationCode(array $payload, Transaction $tx): ?string
    {
        $secretKey = $payload['secret_key'] ?? null;
        if (!$secretKey) {
            $this->failTransaction(
                $tx,
                'missing_verification_code',
                'secret_key missing in payload'
            );
            return null;
        }

        $verificationCode = $this->secretStore->getAndDelete($secretKey);
        if ($verificationCode === null) {
            $this->failTransaction(
                $tx,
                'verification_code_missing_or_expired',
                'Code expired or already used'
            );
            return null;
        }

        return $verificationCode;
    }

    private function completeTransaction(Transaction $tx, DerivResult $result, string $verificationCode): void
    {
        try {
            // Get payment agent login ID from config
            $paymentAgentLoginId = $this->derivConfig->defaultLoginId();
            
            if (empty($paymentAgentLoginId)) {
                throw new RuntimeException('Payment agent login ID not configured');
            }

            $derivTransfer = DerivTransfer::forWithdrawal(
                transactionId: $tx->id(),
                userDerivLoginId: $tx->userDerivLoginId(),
                paymentAgentLoginId: $paymentAgentLoginId,
                amountUsd: $result->amountUsd(), // Directly use Money object from result
                derivTransferId: $result->transferId(),
                derivTxnId: $result->txnId(),
                withdrawalVerificationCode: $verificationCode,
                executedAt: new DateTimeImmutable(),
                rawResponse: $result->raw()
            );

            $tx->recordDerivTransfer($derivTransfer);
            $this->txRepo->save($tx);

            $this->publisher->publish('withdrawals.completed', [
                'transaction_id' => (string)$tx->id(),
                'deriv_txn_id' => $result->txnId(),
                'deriv_transfer_id' => $result->transferId(),
                'amount_usd_cents' => $tx->amountUsd()->cents,
                'completed_at' => (new DateTimeImmutable())->format('c'),
            ]);

            $this->logger->info('DerivDebitWorker: withdrawal completed', [
                'transaction_id' => (string)$tx->id(),
                'deriv_txn_id' => $result->txnId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->critical('DerivDebitWorker: failed to persist completion', [
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

            $this->publisher->publish('withdrawals.failed', [
                'transaction_id' => (string)$tx->id(),
                'reason' => $reason,
                'message' => $message,
                'failed_at' => (new DateTimeImmutable())->format('c'),
            ]);

            $this->logger->warning('DerivDebitWorker: withdrawal failed', [
                'transaction_id' => (string)$tx->id(),
                'reason' => $reason,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logger->critical('DerivDebitWorker: failed to persist failure', [
                'transaction_id' => (string)$tx->id(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}