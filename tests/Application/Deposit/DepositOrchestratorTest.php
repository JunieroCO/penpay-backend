<?php
declare(strict_types=1);

namespace Tests\Application\Deposit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PenPay\Application\Deposit\DepositOrchestrator;
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
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\Currency;
use RuntimeException;
use DateTimeImmutable;

final class DepositOrchestratorTest extends TestCase
{
    private DepositOrchestrator $orchestrator;
    private TransactionRepositoryInterface&MockObject $txRepo;
    private TransactionFactoryInterface&MockObject $txFactory;
    private FxServiceInterface&MockObject $fxService;
    private DailyLimitCheckerInterface&MockObject $dailyLimit;
    private LedgerRecorderInterface&MockObject $ledger;
    private RedisStreamPublisherInterface&MockObject $publisher;
    private (AuditLoggerInterface&MockObject)|null $auditLogger;

    protected function setUp(): void
    {
        $this->txRepo      = $this->createMock(TransactionRepositoryInterface::class);
        $this->txFactory   = $this->createMock(TransactionFactoryInterface::class);
        $this->fxService   = $this->createMock(FxServiceInterface::class);
        $this->dailyLimit  = $this->createMock(DailyLimitCheckerInterface::class);
        $this->ledger      = $this->createMock(LedgerRecorderInterface::class);
        $this->publisher   = $this->createMock(RedisStreamPublisherInterface::class);
        $this->auditLogger = null;

        $this->orchestrator = new DepositOrchestrator(
            $this->txRepo,
            $this->txFactory,
            $this->fxService,
            $this->dailyLimit,
            $this->ledger,
            $this->publisher,
            $this->auditLogger
        );
    }

    public function test_successful_deposit_flow(): void
    {
        $userId = UserId::generate();
        $command = DepositRequestDTO::fromArray([
            'user_id'         => (string) $userId,
            'amount_usd'      => 100.00,
            'device_id'       => 'test-device',
            'idempotency_key' => 'test-idem-123'
        ]);

        $lockedRate = $this->createLockedRate(130.50);
        $transaction = $this->createTransactionMock();

        // Idempotency check returns null (no existing transaction)
        $this->txRepo->expects($this->once())
            ->method('findByIdempotencyKey')
            ->with($this->isInstanceOf(IdempotencyKey::class))
            ->willReturn(null);

        // Daily limit check passes
        $this->dailyLimit->expects($this->once())
            ->method('canDeposit')
            ->with((string) $userId, $this->isInstanceOf(Money::class))
            ->willReturn(true);

        // FX rate is locked
        $this->fxService->expects($this->once())
            ->method('lockRate')
            ->with('USD', 'KES')
            ->willReturn($lockedRate);

        // Transaction is created
        $this->txFactory->expects($this->once())
            ->method('createDepositTransaction')
            ->with(
                (string) $userId,
                100.0,
                $lockedRate,
                $this->isInstanceOf(IdempotencyKey::class)
            )
            ->willReturn($transaction);

        // Transaction is persisted
        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($transaction);

        // Ledger entry is recorded
        $this->ledger->expects($this->once())
            ->method('recordDepositInitiated')
            ->with(
                $this->isType('string'),                    // userId is a string
                $this->isInstanceOf(TransactionId::class),  // transactionId is TransactionId object
                $this->isInstanceOf(Money::class),          // amountUsd is Money
                $this->isInstanceOf(Money::class),          // amountKes is Money
                $this->isInstanceOf(LockedRate::class)      // lockedRate is LockedRate
            );

        // Event is published
        $this->publisher->expects($this->once())
            ->method('publish')
            ->with(
                'deposits.initiated',
                $this->callback(function (array $payload) {
                    return isset($payload['transaction_id']) 
                        && isset($payload['user_id'])
                        && isset($payload['amount_usd']);
                })
            );

        $result = $this->orchestrator->execute($command);

        $this->assertInstanceOf(Transaction::class, $result);
        $this->assertEquals($transaction->getId(), $result->getId());
    }

    public function test_idempotent_deposit_returns_existing_transaction(): void
    {
        $userId = UserId::generate();
        $command = DepositRequestDTO::fromArray([
            'user_id'         => (string) $userId,
            'amount_usd'      => 50.0,
            'idempotency_key' => 'idem-repeat-001'
        ]);

        $existingTx = $this->createTransactionMock();

        // Existing transaction found by idempotency key
        $this->txRepo->expects($this->once())
            ->method('findByIdempotencyKey')
            ->with($this->isInstanceOf(IdempotencyKey::class))
            ->willReturn($existingTx);

        // No further processing should occur
        $this->dailyLimit->expects($this->never())->method('canDeposit');
        $this->fxService->expects($this->never())->method('lockRate');
        $this->txFactory->expects($this->never())->method('createDepositTransaction');
        $this->txRepo->expects($this->never())->method('save');
        $this->ledger->expects($this->never())->method('recordDepositInitiated');
        $this->publisher->expects($this->never())->method('publish');

        $result = $this->orchestrator->execute($command);

        $this->assertInstanceOf(Transaction::class, $result);
        $this->assertEquals($existingTx->getId(), $result->getId());
    }

    public function test_daily_limit_exceeded_throws_exception(): void
    {
        $userId = UserId::generate();
        $command = DepositRequestDTO::fromArray([
            'user_id'         => (string) $userId,
            'amount_usd'      => 999999.0,
            'idempotency_key' => 'test-idem-limit'
        ]);

        // No existing transaction
        $this->txRepo->expects($this->once())
            ->method('findByIdempotencyKey')
            ->willReturn(null);

        // Daily limit check fails
        $this->dailyLimit->expects($this->once())
            ->method('canDeposit')
            ->with((string) $userId, $this->isInstanceOf(Money::class))
            ->willReturn(false);

        // No further processing should occur
        $this->fxService->expects($this->never())->method('lockRate');
        $this->txFactory->expects($this->never())->method('createDepositTransaction');
        $this->txRepo->expects($this->never())->method('save');
        $this->ledger->expects($this->never())->method('recordDepositInitiated');
        $this->publisher->expects($this->never())->method('publish');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Daily deposit limit exceeded');

        $this->orchestrator->execute($command);
    }

    public function test_fx_service_failure_prevents_transaction_creation(): void
    {
        $userId = UserId::generate();
        $command = DepositRequestDTO::fromArray([
            'user_id'         => (string) $userId,
            'amount_usd'      => 100.00,
            'idempotency_key' => 'test-idem-fx-fail'
        ]);

        $this->txRepo->expects($this->once())
            ->method('findByIdempotencyKey')
            ->willReturn(null);

        $this->dailyLimit->expects($this->once())
            ->method('canDeposit')
            ->with((string) $userId, $this->isInstanceOf(Money::class))
            ->willReturn(true);

        // FX service fails
        $this->fxService->expects($this->once())
            ->method('lockRate')
            ->with('USD', 'KES')
            ->willThrowException(new RuntimeException('FX service unavailable'));

        // No transaction should be created or saved
        $this->txFactory->expects($this->never())->method('createDepositTransaction');
        $this->txRepo->expects($this->never())->method('save');
        $this->ledger->expects($this->never())->method('recordDepositInitiated');
        $this->publisher->expects($this->never())->method('publish');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FX service unavailable');

        $this->orchestrator->execute($command);
    }

    public function test_transaction_factory_failure_prevents_persistence(): void
    {
        $userId = UserId::generate();
        $command = DepositRequestDTO::fromArray([
            'user_id'         => (string) $userId,
            'amount_usd'      => 100.00,
            'idempotency_key' => 'test-idem-factory-fail'
        ]);

        $lockedRate = $this->createLockedRate(130.50);

        $this->txRepo->expects($this->once())
            ->method('findByIdempotencyKey')
            ->willReturn(null);

        $this->dailyLimit->expects($this->once())
            ->method('canDeposit')
            ->with((string) $userId, $this->isInstanceOf(Money::class))
            ->willReturn(true);

        $this->fxService->expects($this->once())
            ->method('lockRate')
            ->willReturn($lockedRate);

        // Factory fails to create transaction
        $this->txFactory->expects($this->once())
            ->method('createDepositTransaction')
            ->willThrowException(new RuntimeException('Invalid transaction data'));

        // Nothing should be persisted or published
        $this->txRepo->expects($this->never())->method('save');
        $this->ledger->expects($this->never())->method('recordDepositInitiated');
        $this->publisher->expects($this->never())->method('publish');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid transaction data');

        $this->orchestrator->execute($command);
    }

    public function test_repository_save_failure_prevents_ledger_and_event_publishing(): void
    {
        $userId = UserId::generate();
        $command = DepositRequestDTO::fromArray([
            'user_id'         => (string) $userId,
            'amount_usd'      => 100.00,
            'idempotency_key' => 'test-idem-save-fail'
        ]);

        $lockedRate = $this->createLockedRate(130.50);
        $transaction = $this->createTransactionMock();

        $this->txRepo->expects($this->once())
            ->method('findByIdempotencyKey')
            ->willReturn(null);

        $this->dailyLimit->expects($this->once())
            ->method('canDeposit')
            ->with((string) $userId, $this->isInstanceOf(Money::class))
            ->willReturn(true);

        $this->fxService->expects($this->once())
            ->method('lockRate')
            ->willReturn($lockedRate);

        $this->txFactory->expects($this->once())
            ->method('createDepositTransaction')
            ->willReturn($transaction);

        // Repository save fails
        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($transaction)
            ->willThrowException(new RuntimeException('Database connection lost'));

        // Ledger and publisher should not be called
        $this->ledger->expects($this->never())->method('recordDepositInitiated');
        $this->publisher->expects($this->never())->method('publish');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database connection lost');

        $this->orchestrator->execute($command);
    }

    /**
     * Helper method to create a Transaction for testing
     * Since Transaction is final, we create a real instance
     */
    private function createTransactionMock(): Transaction
    {
        $idempotencyKey = IdempotencyKey::fromHeader('test-key-' . uniqid());
        $amountKes = Money::kes(13050);
        
        return Transaction::initiateDeposit(
            TransactionId::generate(),
            $amountKes,
            $idempotencyKey
        );
    }

    /**
     * Helper method to create a LockedRate instance
     */
    private function createLockedRate(float $rate): LockedRate
    {
        return LockedRate::lock($rate, Currency::USD, Currency::KES);
    }
}