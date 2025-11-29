<?php
declare(strict_types=1);

namespace PenPay\Workers;

use PenPay\Domain\Payments\Aggregate\WithdrawalTransaction;
use PenPay\Domain\Payments\Repository\WithdrawalTransactionRepositoryInterface; 
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Infrastructure\Deriv\Withdrawal\DerivWithdrawalGatewayInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use PenPay\Domain\Payments\Entity\DerivWithdrawalResult;
use Psr\Log\LoggerInterface;
use DateTimeImmutable;
use RuntimeException;
use React\EventLoop\LoopInterface;

final class DerivDebitWorker
{
    public function __construct(
        private readonly WithdrawalTransactionRepositoryInterface $txRepo, 
        private readonly DerivWithdrawalGatewayInterface $derivGateway,
        private readonly OneTimeSecretStoreInterface $secretStore,
        private readonly RedisStreamPublisherInterface $publisher,
        private readonly LoggerInterface $logger,
        private readonly LoopInterface $loop,
        private readonly int $maxRetries = 3
    ) {}

    /**
     * @param array{transaction_id: string, secret_key?: string, usd_cents?: int} $payload
     */
    public function handle(array $payload): void
    {
        $txIdString = $payload['transaction_id'] ?? null;
        if (!$txIdString) {
            $this->logger->warning('DerivDebitWorker: missing transaction_id', $payload);
            return;
        }

        try {
            $txId = TransactionId::fromString($txIdString);
        } catch (\Throwable) {
            $this->logger->error('DerivDebitWorker: invalid transaction_id format', ['transaction_id' => $txIdString]);
            return;
        }

        try {
            $tx = $this->txRepo->getById($txId);
        } catch (\Throwable $e) {
            $this->logger->error('DerivDebitWorker: failed to load transaction', [
                'transaction_id' => (string)$txId,
                'error' => $e->getMessage()
            ]);
            return;
        }

        // Idempotent guard
        if ($tx->isFinalized()) {
            $this->logger->info('DerivDebitWorker: already finalized, skipping', ['transaction_id' => (string)$txId]);
            return;
        }

        // Payment agent credentials
        $paLogin = $tx->paymentAgentLoginId();

        if ($paLogin === null) {
            $this->failAndPublish($tx, 'missing_deriv_payment_agent_credentials', 'PA login not attached');
            return;
        }

        // Get verification code
        $secretKey = $payload['secret_key'] ?? null;
        if (!$secretKey) {
            $this->failAndPublish($tx, 'missing_verification_code', 'secret_key missing in payload');
            return;
        }

        $verificationCode = $this->secretStore->getAndDelete($secretKey);
        if ($verificationCode === null) {
            $this->failAndPublish($tx, 'verification_code_missing_or_expired', 'Code expired or already used');
            return;
        }

        $amountUsd = $tx->amountUsd()->toDecimal();

        try {
            $this->logger->info('DerivDebitWorker: calling paymentagent_withdraw', [
                'transaction_id' => (string)$txId,
                'amount_usd' => $amountUsd,
                'pa_login' => $paLogin
            ]);

            $promise = $this->derivGateway->withdraw(
                loginId: $paLogin,
                amountUsd: $amountUsd,
                verificationCode: $verificationCode,
                reference: (string)$txId
            );

            // Wait for promise to resolve
            $result = null;
            $error = null;

            $promise->then(
                function (DerivWithdrawalResult $res) use (&$result) {
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
                throw new RuntimeException('No result received from gateway');
            }

            if (!$result->isSuccess()) {
                $this->failAndPublish($tx, 'deriv_withdraw_failed', $result->errorMessage() ?? 'Unknown error');
                return;
            }

            $tx->recordDerivDebit(
                derivTransferId: $result->transferId(),
                derivTxnId: $result->txnId(),
                executedAt: new DateTimeImmutable(),
                rawResponse: $result->raw()
            );

            $this->txRepo->save($tx);

            $this->publisher->publish('withdrawals.deriv_debited', [
                'transaction_id' => (string)$tx->id(),
            ]);

            $this->logger->info('DerivDebitWorker: success', ['transaction_id' => (string)$txId]);

        } catch (\Throwable $e) {
            $this->logger->error('DerivDebitWorker: exception', [
                'transaction_id' => (string)$txId,
                'error' => $e->getMessage()
            ]);
            $this->failAndPublish($tx, 'deriv_withdraw_exception', $e->getMessage());
        }
    }

    private function failAndPublish(WithdrawalTransaction $tx, string $reason, string $detail): void
    {
        $tx->fail($reason, $detail);
        $this->txRepo->save($tx);
        $this->publisher->publish('withdrawals.failed', [
            'transaction_id' => (string)$tx->id(),
            'reason' => $reason,
            'detail' => $detail
        ]);
    }
}