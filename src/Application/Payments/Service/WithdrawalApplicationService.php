<?php
declare(strict_types=1);

namespace PenPay\Application\Payments\Service;

use PenPay\Application\Payments\Command\StartWithdrawalCommand;
use PenPay\Application\Payments\Exception\DuplicateTransactionException;
use PenPay\Application\Payments\Exception\PaymentNotAllowedException;
use PenPay\Application\Payments\Policy\WithdrawalEligibilityPolicy;
use PenPay\Application\Payments\Policy\WithdrawalLimitsPolicy;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Factory\TransactionFactoryInterface;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Wallet\Services\FxServiceInterface;
use PenPay\Domain\Wallet\Services\LedgerRecorderInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use PenPay\Infrastructure\Audit\AuditLoggerInterface;
use RuntimeException;
use Throwable;

final readonly class WithdrawalApplicationService
{
    private const STREAM_INITIATED = 'withdrawals.initiated';

    public function __construct(
        private TransactionRepositoryInterface $txRepo,
        private TransactionFactoryInterface $txFactory,
        private FxServiceInterface $fxService,
        private WithdrawalEligibilityPolicy $eligibilityPolicy,
        private WithdrawalLimitsPolicy $limitsPolicy,
        private LedgerRecorderInterface $ledger,
        private RedisStreamPublisherInterface $publisher,
        private OneTimeSecretStoreInterface $secretStore,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * @throws DuplicateTransactionException
     * @throws PaymentNotAllowedException
     */
    public function startWithdrawal(StartWithdrawalCommand $command): Transaction
    {
        $startTime = microtime(true);

        try {
            // Check for existing transaction using the IdempotencyKey value object
            if ($existing = $this->txRepo->findByIdempotencyKey($command->idempotencyKeyHeader)) {
                $this->auditLog('idempotent', $command, $existing, $startTime);
                return $existing;
            }

            // Convert PositiveDecimal to cents for policy checks
            $amountUsdCents = (int) ($command->amountUsdCents->toFloat() * 100);

            $this->eligibilityPolicy->ensureEligible($command->userId->value, $amountUsdCents);
            $this->limitsPolicy->ensureWithinLimits($command->userId->value, $amountUsdCents);

            $lockedRate = $this->fxService->lockRate('USD', 'KES');

            $transaction = $this->txFactory->createWithdrawalFromCents(
                userId: $command->userId->value,
                amountUsdCents: $amountUsdCents,
                lockedRate: $lockedRate,
                userDerivLoginId: $command->userDerivLoginId->value(),
                withdrawalVerificationCode: $command->withdrawalVerificationCode->toString(),
                idempotencyKey: $command->idempotencyKeyHeader
            );

            $secretKey = 'verif_' . bin2hex(random_bytes(16));
            $this->secretStore->store(
                key: $secretKey,
                value: $command->withdrawalVerificationCode->toString(),
                ttlSeconds: 600
            );

            $this->ledger->recordWithdrawalInitiated(
                userId: $command->userId->value,
                transactionId: $transaction->id(),
                amountUsd: $transaction->amountUsd(),
                lockedRate: $lockedRate
            );

            $this->txRepo->save($transaction);

            $this->publisher->publish(self::STREAM_INITIATED, [
                'transaction_id' => (string) $transaction->id(),
                'user_id'        => $command->userId->value,
                'usd_cents'      => $amountUsdCents,
                'secret_key'     => $secretKey,
                'rate'           => $lockedRate->rate(),
                'expires_at'     => $lockedRate->expiresAt()->format('c'),
                'timestamp'      => time(),
            ]);

            $this->auditLog('created', $command, $transaction, $startTime);

            return $transaction;

        } catch (DuplicateTransactionException $e) {
            throw $e;
        } catch (PaymentNotAllowedException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->auditLog('failed', $command, null, $startTime, $e->getMessage());
            throw new RuntimeException('Withdrawal failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function auditLog(string $outcome, StartWithdrawalCommand $cmd, ?Transaction $tx, float $start, ?string $error = null): void
    {
        // Convert PositiveDecimal to cents for logging
        $amountUsdCents = (int) ($cmd->amountUsdCents->toFloat() * 100);

        $base = [
            'event'            => 'withdrawal.' . $outcome,
            'user_id'          => $cmd->userId->value,
            'amount_usd_cents' => $amountUsdCents,
            'deriv_login_id'   => $cmd->userDerivLoginId->value(),
            'idempotency_key'  => $cmd->idempotencyKeyHeader->value,
            'duration_ms'      => round((microtime(true) - $start) * 1000, 2),
            'timestamp'        => date('c'),
        ];

        if ($tx) {
            $base['transaction_id'] = (string) $tx->id();
        }

        if ($error) {
            $base['error'] = $error;
        }

        if ($outcome === 'failed') {
            $this->auditLogger?->error('withdrawal.failed', $base);
        } else {
            $this->auditLogger?->info('withdrawal.success', $base);
        }
    }
}