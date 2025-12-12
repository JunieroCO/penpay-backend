<?php
declare(strict_types=1);

namespace PenPay\Tests\Application\Withdrawal;

use PHPUnit\Framework\TestCase;
use PenPay\Application\Withdrawal\WithdrawalOrchestrator;
use PenPay\Application\Withdrawal\DTO\WithdrawalRequestDTO;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Wallet\Services\DailyLimitCheckerInterface;
use PenPay\Domain\Wallet\Services\LedgerRecorderInterface;
use PenPay\Infrastructure\Fx\FxServiceInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Wallet\ValueObject\Currency;
use PenPay\Domain\Shared\Kernel\TransactionId;
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

    /** @test */
    public function it_creates_withdrawal_transaction_successfully(): void
    {
        $userId = UserId::generate();
        $dto = WithdrawalRequestDTO::fromArray([
            'user_id' => (string) $userId,
            'amount_usd' => '50.00',
            'verification_code' => 'ABC123',
            'user_deriv_login_id' => 'CR123456',
            'idempotency_key' => 'idem-123',
        ]);

        $lockedRate = LockedRate::lock(0.0076, Currency::USD, Currency::KES);

        $this->txRepo->expects($this->once())
            ->method('findByIdempotencyKey')
            ->willReturn(null);

        $this->limitChecker->expects($this->once())
            ->method('canWithdraw')
            ->with((string) $userId, Money::usd(5000))
            ->willReturn(true);

        $this->fxService->expects($this->once())
            ->method('lockRate')
            ->with('USD', 'KES')
            ->willReturn($lockedRate);

        $this->secretStore->expects($this->once())
            ->method('store')
            ->with($this->stringStartsWith('verif_'), 'ABC123', 600);

        $this->ledgerRecorder->expects($this->once())
            ->method('recordWithdrawalInitiated');

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class));

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.initiated', $this->callback(function ($payload) use ($userId) {
                return $payload['usd_cents'] === 5000
                    && $payload['user_id'] === (string) $userId
                    && str_starts_with($payload['secret_key'], 'verif_');
            }));

        $transaction = $this->orchestrator->execute($dto);

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertSame(TransactionType::WITHDRAWAL, $transaction->type());
        $this->assertSame(5000, $transaction->amountUsd()->cents);
        $this->assertNotNull($transaction->idempotencyKey());
    }

    /** @test */
    public function it_returns_existing_transaction_on_idempotency_hit(): void
    {
        $userId = UserId::generate();
        $dto = WithdrawalRequestDTO::fromArray([
            'user_id' => (string) $userId,
            'amount_usd' => '100.00',
            'verification_code' => 'ABC123',
            'user_deriv_login_id' => 'CR999999',
            'idempotency_key' => 'idem-456',
        ]);

        // Create a real Transaction instance instead of a mock
        $existingTx = Transaction::initiateWithdrawal(
            TransactionId::generate(),
            (string) $userId,
            Money::usd(10000),
            LockedRate::lock(0.0076, Currency::USD, Currency::KES),
            IdempotencyKey::fromHeader('idem-456'),
            'CR999999',
            'ABC123'
        );

        $this->txRepo->method('findByIdempotencyKey')
            ->willReturn($existingTx);

        $transaction = $this->orchestrator->execute($dto);

        $this->assertSame($existingTx, $transaction);

        // No other calls should happen
        $this->limitChecker->expects($this->never())->method('canWithdraw');
        $this->fxService->expects($this->never())->method('lockRate');
        $this->secretStore->expects($this->never())->method('store');
    }

    /** @test */
    public function it_throws_when_daily_limit_exceeded(): void
    {
        $userId = UserId::generate();
        $dto = WithdrawalRequestDTO::fromArray([
            'user_id' => (string) $userId,
            'amount_usd' => '1000.00',
            'verification_code' => 'ABC123',
            'user_deriv_login_id' => 'CR888888',
        ]);

        $this->txRepo->method('findByIdempotencyKey')->willReturn(null);
        $this->limitChecker->method('canWithdraw')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Daily withdrawal limit exceeded');

        $this->orchestrator->execute($dto);
    }

    /** @test */
    public function it_throws_on_idempotency_collision_with_wrong_type(): void
    {
        $userId = UserId::generate();
        $dto = WithdrawalRequestDTO::fromArray([
            'user_id' => (string) $userId,
            'amount_usd' => '50.00',
            'verification_code' => 'ABC123',
            'user_deriv_login_id' => 'CR111111',
            'idempotency_key' => 'collision-key',
        ]);

        // Create a real DEPOSIT transaction instead of a mock
        $existingTx = Transaction::initiateDeposit(
            TransactionId::generate(),
            (string) $userId,
            Money::usd(5000),
            LockedRate::lock(130.50, Currency::USD, Currency::KES),
            IdempotencyKey::fromHeader('collision-key'),
            'CR111111'
        );

        $this->txRepo->method('findByIdempotencyKey')->willReturn($existingTx);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Idempotency key collision - wrong transaction type');

        $this->orchestrator->execute($dto);
    }
}