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
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\Wallet\ValueObject\Money;           
use PenPay\Domain\Wallet\ValueObject\Currency;        
use RuntimeException;
use Throwable;

final readonly class DepositOrchestrator
{
    private const STREAM_DEPOSIT_INITIATED = 'deposits.initiated';

    public function __construct(
        private TransactionRepositoryInterface     $txRepo,
        private TransactionFactoryInterface        $txFactory,
        private FxServiceInterface                 $fxService,
        private DailyLimitCheckerInterface         $dailyLimit,
        private LedgerRecorderInterface            $ledger,
        private RedisStreamPublisherInterface      $publisher,
        private ?AuditLoggerInterface              $auditLogger = null,
    ) {}

    public function execute(DepositRequestDTO $command): Transaction
    {
        $startTime = microtime(true);
        $userId    = $command->userId;
        $amountUsd = $command->amountUsd->toFloat();
        $amountUsdMoney = Money::fromDecimal($amountUsd, Currency::USD);

        try {
            // 1. Idempotency
            $idempotencyKey = $command->idempotencyKey
                ? IdempotencyKey::fromHeader($command->idempotencyKey)
                : null;

            if ($idempotencyKey) {
                $existing = $this->txRepo->findByIdempotencyKey($idempotencyKey);
                if ($existing) {
                    $this->auditSuccess($command, $existing, $startTime, 'idempotent');
                    return $existing;
                }
            }

            // 2. Daily limit
            if (!$this->dailyLimit->canDeposit((string) $userId, $amountUsdMoney)) {
                throw new RuntimeException('Daily deposit limit exceeded');
            }

            // 3. Lock FX rate
            $lockedRate = $this->fxService->lockRate('USD', 'KES');

            // 4. Create transaction
            $transaction = $this->txFactory->createDepositTransaction(
                userId: (string) $userId,
                amountUsd: $amountUsd,
                lockedRate: $lockedRate,
                idempotencyKey: $idempotencyKey
            );

            // 5. Persist
            $this->txRepo->save($transaction);

            // 6. Ledger
            $this->ledger->recordDepositInitiated(
                userId: (string) $userId,              
                transactionId: $transaction->getId(),   
                amountUsd: $amountUsdMoney,
                amountKes: $transaction->getAmount(),
                lockedRate: $lockedRate
            );

            // 7. Publish event
            $this->publisher->publish(self::STREAM_DEPOSIT_INITIATED, [
                'transaction_id' => (string) $transaction->getId(),
                'user_id'        => (string) $userId,
                'amount_usd'     => $amountUsd,
                'amount_kes'     => $transaction->getAmount()->cents,
                'rate'           => $lockedRate->rate,  
                'device_id'      => $command->deviceId,
                'timestamp'      => time(),
            ]);

            $this->auditSuccess($command, $transaction, $startTime, 'created');
            return $transaction;

        } catch (Throwable $e) {
            $this->auditFailure($command, $e, $startTime);
            throw new RuntimeException('Deposit failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function auditSuccess(
        DepositRequestDTO $command,
        Transaction $tx,
        float $start,
        string $outcome
    ): void {
        $this->auditLogger?->info('deposit.success', [
            'event'           => 'deposit_success',
            'transaction_id'  => (string) $tx->getId(),
            'user_id'         => (string) $command->userId,
            'amount_usd'      => $command->amountUsd->toFloat(),
            'amount_kes'      => $tx->getAmount()->cents,
            'outcome'         => $outcome,
            'device_id'       => $command->deviceId,
            'idempotency_key' => $command->idempotencyKey,
            'duration_ms'     => round((microtime(true) - $start) * 1000, 2),
            'timestamp'       => date('c'),
        ]);
    }

    private function auditFailure(DepositRequestDTO $command, Throwable $e, float $start): void
    {
        $this->auditLogger?->error('deposit.failed', [
            'event'           => 'deposit_failed',
            'user_id'         => (string) $command->userId,
            'amount_usd'      => $command->amountUsd->toFloat(),
            'device_id'       => $command->deviceId,
            'idempotency_key' => $command->idempotencyKey,
            'error'           => $e->getMessage(),
            'duration_ms'     => round((microtime(true) - $start) * 1000, 2),
            'timestamp'       => date('c'),
        ]);
    }
}