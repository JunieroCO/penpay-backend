<?php
declare(strict_types=1);

namespace PenPay\Application\Withdrawal;

use PenPay\Application\Withdrawal\DTO\WithdrawalRequestDTO;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Wallet\Services\DailyLimitCheckerInterface;
use PenPay\Domain\Wallet\Services\LedgerRecorderInterface;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Infrastructure\Fx\FxServiceInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use PenPay\Infrastructure\Audit\AuditLoggerInterface;
use RuntimeException;
use Throwable;

final readonly class WithdrawalOrchestrator
{
    private const STREAM_WITHDRAWAL_INITIATED = 'withdrawals.initiated';

    public function __construct(
        private TransactionRepositoryInterface $txRepo,
        private DailyLimitCheckerInterface     $dailyLimitChecker,
        private FxServiceInterface             $fxService,
        private LedgerRecorderInterface        $ledgerRecorder,
        private RedisStreamPublisherInterface  $publisher,
        private OneTimeSecretStoreInterface    $secretStore,
        private ?AuditLoggerInterface          $auditLogger = null,
    ) {}

    public function execute(WithdrawalRequestDTO $dto): Transaction
    {
        $startTime = microtime(true);

        try {
            // 1. Idempotency
            $idempotencyKey = $dto->idempotencyKey
                ? IdempotencyKey::fromHeader($dto->idempotencyKey)
                : IdempotencyKey::generate();

            if ($existing = $this->txRepo->findByIdempotencyKey($idempotencyKey)) {
                if ($existing->type() !== \PenPay\Domain\Payments\ValueObject\TransactionType::WITHDRAWAL) {
                    throw new RuntimeException('Idempotency key collision - wrong transaction type');
                }
                $this->auditSuccess($dto, $existing, $startTime, 'idempotent');
                return $existing;
            }

            // 2. Daily Limit
            $amountUsd = Money::usd($dto->toCents());
            if (!$this->dailyLimitChecker->canWithdraw((string) $dto->userId, $amountUsd)) {
                throw new RuntimeException('Daily withdrawal limit exceeded');
            }

            // 3. Lock FX Rate
            $lockedRate = $this->fxService->lockRate('USD', 'KES');

            // 4. Create Transaction
            $tx = Transaction::initiateWithdrawal(
                id: \PenPay\Domain\Shared\Kernel\TransactionId::generate(),
                userId: (string) $dto->userId,
                amountUsd: $amountUsd,
                lockedRate: $lockedRate,
                idempotencyKey: $idempotencyKey,
                userDerivLoginId: $dto->userDerivLoginId,
                withdrawalVerificationCode: $dto->verificationCode
            );

            // 5. Store Verification Code (Encrypted)
            $secretKey = 'verif_' . bin2hex(random_bytes(16));
            $this->secretStore->store(
                key: $secretKey,
                value: $dto->verificationCode,
                ttlSeconds: 600
            );

            // 6. Ledger
            $this->ledgerRecorder->recordWithdrawalInitiated(
                userId: (string) $dto->userId,
                transactionId: $tx->id(),
                amountUsd: $amountUsd,
                lockedRate: $lockedRate
            );

            // 7. Persist
            $this->txRepo->save($tx);

            // 8. Publish
            $this->publisher->publish(self::STREAM_WITHDRAWAL_INITIATED, [
                'transaction_id'  => (string) $tx->id(),
                'user_id'         => (string) $dto->userId,
                'usd_cents'       => $amountUsd->cents,
                'secret_key'      => $secretKey,
                'rate'            => $lockedRate->rate(),
                'expires_at'      => $lockedRate->expiresAt()->format('c'),
                'device_id'       => $dto->deviceId,
                'timestamp'       => time(),
            ]);

            $this->auditSuccess($dto, $tx, $startTime, 'created');
            return $tx;

        } catch (Throwable $e) {
            $this->auditFailure($dto, $e, $startTime);
            throw new RuntimeException('Withdrawal failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function auditSuccess(
        WithdrawalRequestDTO $dto,
        Transaction $tx,
        float $startTime,
        string $outcome
    ): void {
        $this->auditLogger?->info('withdrawal.success', [
            'event'             => 'withdrawal_success',
            'transaction_id'    => (string) $tx->id(),
            'user_id'           => (string) $dto->userId,
            'amount_usd_cents'  => $tx->amountUsd()->cents,
            'amount_kes_cents'  => $tx->amountKes()->cents,
            'deriv_login_id'    => $dto->userDerivLoginId,
            'device_id'         => $dto->deviceId,
            'idempotency_key'   => $dto->idempotencyKey,
            'outcome'           => $outcome,
            'duration_ms'       => round((microtime(true) - $startTime) * 1000, 2),
            'timestamp'         => date('c'),
        ]);
    }

    private function auditFailure(
        WithdrawalRequestDTO $dto,
        Throwable $e,
        float $startTime
    ): void {
        $this->auditLogger?->error('withdrawal.failed', [
            'event'             => 'withdrawal_failed',
            'user_id'           => (string) $dto->userId,
            'amount_usd'        => $dto->amountUsd->toFloat(),
            'deriv_login_id'    => $dto->userDerivLoginId,
            'device_id'         => $dto->deviceId,
            'idempotency_key'   => $dto->idempotencyKey,
            'error'             => $e->getMessage(),
            'duration_ms'       => round((microtime(true) - $startTime) * 1000, 2),
            'timestamp'         => date('c'),
        ]);
    }
}