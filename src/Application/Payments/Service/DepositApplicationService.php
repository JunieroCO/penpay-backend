<?php
declare(strict_types=1);

namespace PenPay\Application\Payments\Service;

use PenPay\Application\Payments\Command\StartDepositCommand;
use PenPay\Application\Payments\Exception\DuplicateTransactionException;
use PenPay\Application\Payments\Exception\PaymentNotAllowedException;
use PenPay\Application\Payments\Policy\DepositEligibilityPolicy;
use PenPay\Application\Payments\Policy\DepositLimitsPolicy;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Factory\TransactionFactoryInterface;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Wallet\Services\FxServiceInterface;
use PenPay\Domain\Wallet\Services\LedgerRecorderInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Audit\AuditLoggerInterface;
use RuntimeException;
use Throwable;

final readonly class DepositApplicationService
{
    private const STREAM_INITIATED = 'deposits.initiated';

    public function __construct(
        private TransactionRepositoryInterface $txRepo,
        private TransactionFactoryInterface $txFactory,
        private FxServiceInterface $fxService,
        private DepositEligibilityPolicy $eligibilityPolicy,
        private DepositLimitsPolicy $limitsPolicy,
        private LedgerRecorderInterface $ledger,
        private RedisStreamPublisherInterface $publisher,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * @throws DuplicateTransactionException
     * @throws PaymentNotAllowedException
     */
    public function startDeposit(StartDepositCommand $command): Transaction
    {
        $startTime = microtime(true);

        try {
            // Check for existing transaction using the IdempotencyKey value object
            if ($existing = $this->txRepo->findByIdempotencyKey($command->idempotencyKey)) {
                $this->auditLog('idempotent', $command, $existing, $startTime);
                return $existing;
            }

            // Convert PositiveDecimal to cents for policy checks
            $amountUsdCents = (int) ($command->amountUsd->toFloat() * 100);

            $this->eligibilityPolicy->ensureEligible($command->userId->value, $amountUsdCents);
            $this->limitsPolicy->ensureWithinLimits($command->userId->value, $amountUsdCents);

            $lockedRate = $this->fxService->lockRate('USD', 'KES');

            $transaction = $this->txFactory->createDepositFromCents(
                userId: $command->userId->value,
                amountUsdCents: $amountUsdCents,
                lockedRate: $lockedRate,
                userDerivLoginId: $command->userDerivLoginId->value(),
                idempotencyKey: $command->idempotencyKey
            );

            $this->txRepo->save($transaction);

            $this->ledger->recordDepositInitiated(
                userId: $command->userId->value,
                transactionId: $transaction->id(),
                amountUsd: $transaction->amountUsd(),
                amountKes: $transaction->amountKes(),
                lockedRate: $lockedRate
            );

            $this->publisher->publish(self::STREAM_INITIATED, [
                'transaction_id'     => (string) $transaction->id(),
                'user_id'            => $command->userId->value,
                'amount_usd_cents'   => $amountUsdCents,
                'amount_kes_cents'   => $transaction->amountKes()->cents,
                'rate'               => $lockedRate->rate,
                'deriv_login_id'     => $command->userDerivLoginId->value(),
                'device_id'          => $command->deviceId,
                'idempotency_key'    => $command->idempotencyKey->value,
                'timestamp'          => time(),
            ]);

            $this->auditLog('created', $command, $transaction, $startTime);

            return $transaction;

        } catch (DuplicateTransactionException $e) {
            throw $e;
        } catch (PaymentNotAllowedException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->auditLog('failed', $command, null, $startTime, $e->getMessage());
            throw new RuntimeException('Deposit failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function auditLog(string $outcome, StartDepositCommand $cmd, ?Transaction $tx, float $start, ?string $error = null): void
    {
        // Convert PositiveDecimal to cents for logging
        $amountUsdCents = (int) ($cmd->amountUsd->toFloat() * 100);

        $base = [
            'event'            => 'deposit.' . $outcome,
            'user_id'          => $cmd->userId->value,
            'amount_usd_cents' => $amountUsdCents,
            'deriv_login_id'   => $cmd->userDerivLoginId->value(),
            'device_id'        => $cmd->deviceId,
            'idempotency_key'  => $cmd->idempotencyKey->value,
            'duration_ms'      => round((microtime(true) - $start) * 1000, 2),
            'timestamp'        => date('c'),
        ];

        if ($tx) {
            $base['transaction_id'] = (string) $tx->id();
            $base['amount_kes_cents'] = $tx->amountKes()->cents;
        }

        if ($error) {
            $base['error'] = $error;
        }

        if ($outcome === 'failed') {
            $this->auditLogger?->error('deposit.failed', $base);
        } else {
            $this->auditLogger?->info('deposit.success', $base);
        }
    }
}