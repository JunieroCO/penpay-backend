<?php
declare(strict_types=1);

namespace PenPay\Tests\Application\Withdrawal;

use PHPUnit\Framework\TestCase;
use PenPay\Application\Withdrawal\WithdrawalOrchestrator;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Wallet\Services\DailyLimitCheckerInterface;
use PenPay\Domain\Wallet\Services\LedgerRecorderInterface;
use PenPay\Infrastructure\Fx\FxServiceInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PHPUnit\Framework\MockObject\MockObject;

final class WithdrawalOrchestratorTest extends TestCase
{
    private TransactionRepositoryInterface|MockObject $txRepo;
    private DailyLimitCheckerInterface|MockObject $limitChecker;
    private FxServiceInterface|MockObject $fxService;
    private LedgerRecorderInterface|MockObject $ledgerRecorder;
    private RedisStreamPublisherInterface|MockObject $publisher;
    private OneTimeSecretStoreInterface|MockObject $secretStore;

    private WithdrawalOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->txRepo = $this->createMock(TransactionRepositoryInterface::class);
        $this->limitChecker = $this->createMock(DailyLimitCheckerInterface::class);
        $this->fxService = $this->createMock(FxServiceInterface::class);
        $this->ledgerRecorder = $this->createMock(LedgerRecorderInterface::class);
        $this->publisher = $this->createMock(RedisStreamPublisherInterface::class);
        $this->secretStore = $this->createMock(OneTimeSecretStoreInterface::class);

        $this->orchestrator = new WithdrawalOrchestrator(
            $this->txRepo,
            $this->limitChecker,
            $this->fxService,
            $this->ledgerRecorder,
            $this->publisher,
            $this->secretStore
        );
    }

    public function testConfirmWithdrawalCreatesTransaction(): void
    {
        $userId = 'user-123';
        $usdCents = 5000;
        $verificationCode = 'cqLURMba';
        $idempotencyKey = IdempotencyKey::fromHeader('idem-123');

        $this->txRepo->expects($this->once())
            ->method('findByIdempotencyKey')
            ->with($idempotencyKey)
            ->willReturn(null);

        $this->limitChecker->expects($this->once())
            ->method('canWithdraw')
            ->with($userId, Money::usd($usdCents))
            ->willReturn(true);

        // FIX: Use real LockedRate instance (since it's final)
        $lockedRate = \PenPay\Domain\Wallet\ValueObject\LockedRate::lock(
            0.0076,
            \PenPay\Domain\Wallet\ValueObject\Currency::USD,
            \PenPay\Domain\Wallet\ValueObject\Currency::KES
        );

        $this->fxService->expects($this->once())
            ->method('lockRate')
            ->with('USD','KES')
            ->willReturn($lockedRate);

        $this->ledgerRecorder->expects($this->once())
            ->method('recordWithdrawalInitiated');

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($transaction) use ($usdCents) {
                return $transaction instanceof Transaction 
                    && $transaction->getType() === TransactionType::WITHDRAWAL
                    && $transaction->getAmount()->cents === $usdCents;
            }));

        $this->secretStore->expects($this->once())
            ->method('store');

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.initiated', $this->callback(fn($payload) => 
                $payload['usd_cents'] === $usdCents && $payload['user_id'] === $userId
            ));

        $tx = $this->orchestrator->confirmWithdrawal($userId, $usdCents, $verificationCode, $idempotencyKey);

        $this->assertInstanceOf(Transaction::class, $tx);
        $this->assertEquals(TransactionType::WITHDRAWAL, $tx->getType());
        $this->assertEquals($usdCents, $tx->getAmount()->cents);
    }

    public function testConfirmWithdrawalIdempotentReturnsExisting(): void
    {
        $idempotencyKey = IdempotencyKey::fromHeader('idem-456');
        
        // FIX: Use TransactionId::generate() for valid UUID
        $existingTx = Transaction::initiateWithdrawal(
            TransactionId::generate(), // Use generate() instead of fromString()
            Money::usd(1000),
            $idempotencyKey
        );

        $this->txRepo->method('findByIdempotencyKey')->willReturn($existingTx);

        $tx = $this->orchestrator->confirmWithdrawal('user-123', 1000, 'u3hvLDG0', $idempotencyKey);

        $this->assertSame($existingTx, $tx);
    }

    public function testConfirmWithdrawalExceedsDailyLimitThrows(): void
    {
        $this->txRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->limitChecker->method('canWithdraw')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Daily withdrawal limit exceeded');

        $this->orchestrator->confirmWithdrawal('user-123', 10000, 'vMWJQbJH', IdempotencyKey::fromHeader('idem-789'));
    }
}