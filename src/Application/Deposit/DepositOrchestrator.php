<?php
declare(strict_types=1);

namespace PenPay\Application\Deposit;

use PenPay\Application\Deposit\DTO\DepositRequestDTO;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\Factory\TransactionFactoryInterface;
use PenPay\Domain\Wallet\Services\FxServiceInterface;
use PenPay\Domain\Wallet\Services\DailyLimitCheckerInterface;
use PenPay\Domain\Wallet\Services\LedgerRecorderInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Audit\AuditLoggerInterface;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Wallet\ValueObject\Money;
use RuntimeException;
use Throwable;

final readonly class DepositOrchestrator
{
    private const STREAM_DEPOSIT_INITIATED = 'deposits.initiated';

    public function __construct(
        private TransactionRepositoryInterface $txRepo,
        private TransactionFactoryInterface    $txFactory,
        private FxServiceInterface             $fxService,
        private DailyLimitCheckerInterface     $dailyLimit,
        private LedgerRecorderInterface        $ledger,
        private RedisStreamPublisherInterface  $publisher,
        private ?AuditLoggerInterface          $auditLogger = null,
    ) {}

    public function execute(DepositRequestDTO $dto): Transaction
    {
        $startTime = microtime(true);
        $userId    = $dto->userId;
        $amountUsd = Money::usd((int) round($dto->amountUsd->toFloat() * 100));

        try {
            // 1. Idempotency Check
            if ($dto->idempotencyKey) {
                $idempotencyKeyObj = IdempotencyKey::fromHeader($dto->idempotencyKey);
                $existing = $this->txRepo->findByIdempotencyKey($idempotencyKeyObj);
                if ($existing) {
                    $this->auditSuccess($dto, $existing, $startTime, 'idempotent');
                    return $existing;
                }
            }

            // 2. Daily Limit
            $amountUsdFloat = $dto->amountUsd->toFloat();
            $amountUsdMoney = Money::usd((int) round($amountUsdFloat * 100));
            if (!$this->dailyLimit->canDeposit((string) $userId, $amountUsd)) {
                throw new RuntimeException('Daily deposit limit exceeded');
            }

            // 3. Lock FX Rate
            $lockedRate = $this->fxService->lockRate('USD', 'KES');

            // 4. Create Transaction via Factory (cents-based, safe)
            $idempotencyKey = $dto->idempotencyKey
                ? IdempotencyKey::fromHeader($dto->idempotencyKey)
                : null;
                
            $transaction = $this->txFactory->createDepositTransaction(
                userId: (string) $userId,
                amountUsd: $amountUsdFloat,
                lockedRate: $lockedRate,
                userDerivLoginId: $dto->userDerivLoginId,
                idempotencyKey: $idempotencyKey
            );

            // 5. Persist
            $this->txRepo->save($transaction);

            // 6. Ledger (async-safe)
            $this->ledger->recordDepositInitiated(
                userId: (string) $userId,
                transactionId: $transaction->id(),
                amountUsd: $amountUsd,
                amountKes: $transaction->amountKes(),
                lockedRate: $lockedRate
            );

            // 7. Publish to Stream (fire-and-forget)
            $this->publisher->publish(self::STREAM_DEPOSIT_INITIATED, [
                'transaction_id'     => (string) $transaction->id(),
                'user_id'            => (string) $userId,
                'amount_usd_cents'   => $amountUsd->cents,
                'amount_kes_cents'   => $transaction->amountKes()->cents,
                'rate'               => $lockedRate->rate,
                'deriv_login_id'     => $dto->userDerivLoginId,
                'device_id'          => $dto->deviceId,
                'idempotency_key'    => $dto->idempotencyKey,
                'timestamp'          => time(),
            ]);

            $this->auditSuccess($dto, $transaction, $startTime, 'created');

            return $transaction;

        } catch (Throwable $e) {
            $this->auditFailure($dto, $e, $startTime);
            throw new RuntimeException('Deposit failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function auditSuccess(
        DepositRequestDTO $dto,
        Transaction $tx,
        float $start,
        string $outcome
    ): void {
        $this->auditLogger?->info('deposit.success', [
            'event'            => 'deposit_success',
            'transaction_id'   => (string) $tx->id(),
            'user_id'          => (string) $dto->userId,
            'amount_usd_cents' => $tx->amountUsd()->cents,
            'amount_kes_cents' => $tx->amountKes()->cents,
            'deriv_login_id'   => $dto->userDerivLoginId,
            'outcome'          => $outcome,
            'device_id'        => $dto->deviceId,
            'idempotency_key'  => $dto->idempotencyKey,
            'duration_ms'      => round((microtime(true) - $start) * 1000, 2),
            'timestamp'        => date('c'),
        ]);
    }

    private function auditFailure(DepositRequestDTO $dto, Throwable $e, float $start): void
    {
        $this->auditLogger?->error('deposit.failed', [
            'event'           => 'deposit_failed',
            'user_id'         => (string) $dto->userId,
            'amount_usd'      => $dto->amountUsd->toFloat(),
            'deriv_login_id'  => $dto->userDerivLoginId,
            'device_id'       => $dto->deviceId,
            'idempotency_key' => $dto->idempotencyKey,
            'error'           => $e->getMessage(),
            'duration_ms'     => round((microtime(true) - $start) * 1000, 2),
            'timestamp'       => date('c'),
        ]);
    }
}