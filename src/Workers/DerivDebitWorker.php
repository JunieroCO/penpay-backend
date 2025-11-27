<?php
declare(strict_types=1);

namespace PenPay\Workers;

use PenPay\Domain\Payments\Aggregate\WithdrawalTransaction;
use PenPay\Domain\Payments\Repository\WithdrawalTransactionRepositoryInterface; 
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Infrastructure\Deriv\DerivGatewayInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use Psr\Log\LoggerInterface;
use DateTimeImmutable;
use RuntimeException;

final class DerivDebitWorker
{
    public function __construct(
        private readonly WithdrawalTransactionRepositoryInterface $txRepo, 
        private readonly DerivGatewayInterface $derivGateway,
        private readonly OneTimeSecretStoreInterface $secretStore,
        private readonly RedisStreamPublisherInterface $publisher,
        private readonly LoggerInterface $logger,
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

        $tx = $this->txRepo->getById($txId);
        if (!$tx instanceof WithdrawalTransaction) {
            $this->logger->warning('DerivDebitWorker: transaction not found or wrong type', ['transaction_id' => (string)$txId]);
            return;
        }

        // Idempotent guard
        if ($tx->isFinalized()) {
            $this->logger->info('DerivDebitWorker: already finalized, skipping', ['transaction_id' => (string)$txId]);
            return;
        }

        // Payment agent credentials
        $paLogin = $tx->paymentAgentLoginId();
        $paToken = $tx->paymentAgentToken();

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

            $result = $this->derivGateway->paymentAgentWithdraw(
                loginId: $paLogin,
                amountUsd: $amountUsd,
                verificationCode: $verificationCode,
                reference: (string)$txId
            );

            if (!$result->success) {
                $this->failAndPublish($tx, 'deriv_withdraw_failed', $result->errorMessage ?? 'Unknown error');
                return;
            }

            $tx->recordDerivDebit(
                derivTransferId: $result->transferId,
                derivTxnId: $result->txnId,
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