<?php
declare(strict_types=1);

namespace PenPay\Tests\Workers\Withdrawal;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use PenPay\Workers\Withdrawal\DerivDebitWorker;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\Entity\DerivResult;
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Infrastructure\Deriv\DerivConfig;
use PenPay\Infrastructure\Deriv\Withdrawal\DerivWithdrawalGatewayInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use React\Promise\Deferred;

final class DerivDebitWorkerTest extends TestCase
{
    private TransactionRepositoryInterface&MockObject $repo;
    private DerivWithdrawalGatewayInterface&MockObject $gateway;
    private DerivConfig&MockObject $derivConfig;
    private OneTimeSecretStoreInterface&MockObject $secretStore;
    private RedisStreamPublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;
    private LoopInterface&MockObject $loop;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(TransactionRepositoryInterface::class);
        $this->gateway = $this->createMock(DerivWithdrawalGatewayInterface::class);
        $this->derivConfig = $this->createMock(DerivConfig::class);
        $this->secretStore = $this->createMock(OneTimeSecretStoreInterface::class);
        $this->publisher = $this->createMock(RedisStreamPublisherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->loop = $this->createMock(LoopInterface::class);
    }

    private function createWithdrawalTransaction(): Transaction
    {
        $tx = Transaction::initiateWithdrawal(
            id: TransactionId::generate(),
            userId: 'U123',
            amountUsd: Money::usd(10000), // $100
            lockedRate: LockedRate::lock(100.0), // 1 USD = 100 KES
            idempotencyKey: IdempotencyKey::fromHeader('withdrawal-test-' . uniqid()),
            userDerivLoginId: 'CR123456',
            withdrawalVerificationCode: 'ABC123'
        );
        
        // For withdrawal transactions, we need to call the appropriate state transition methods
        // Based on your Transaction aggregate code, withdrawals use:
        // 1. markDerivWithdrawalInitiated() to go from PENDING → PROCESSING
        // 2. markAwaitingDerivConfirmation() to go from PROCESSING → AWAITING_DERIV_CONFIRMATION
        
        $tx->markDerivWithdrawalInitiated(); // PENDING → PROCESSING
        $tx->markAwaitingDerivConfirmation(); // PROCESSING → AWAITING_DERIV_CONFIRMATION
        
        return $tx;
    }

    private function createWorker(): DerivDebitWorker
    {
        return new DerivDebitWorker(
            $this->repo,
            $this->gateway,
            $this->derivConfig,
            $this->secretStore,
            $this->publisher,
            $this->logger,
            $this->loop
        );
    }

    /** @test */
    public function it_processes_successful_deriv_debit(): void
    {
        $tx = $this->createWithdrawalTransaction();
        $txId = (string) $tx->id();

        // Mock repository
        $this->repo->expects($this->once())
            ->method('getById')
            ->with($this->callback(fn($id) => (string)$id === $txId))
            ->willReturn($tx);

        // Mock deriv config
        $this->derivConfig->expects($this->exactly(2))
            ->method('defaultLoginId')
            ->willReturn('PA1234567');

        // Mock secret store
        $this->secretStore
            ->expects($this->once())
            ->method('getAndDelete')
            ->with('secret-key-1')
            ->willReturn('ABC123');

        // Mock Deriv API response
        $result = DerivResult::success(
            transferId: 'T123',
            txnId: 'D456',
            amountUsd: Money::usd(10000),
            rawResponse: ['ok' => true]
        );

        // Create a simple promise that's already resolved
        $promise = \React\Promise\resolve($result);

        $this->gateway
            ->expects($this->once())
            ->method('withdraw')
            ->with(
                'CR123456',
                100.00,
                'ABC123',
                $txId
            )
            ->willReturn($promise);

        // Don't mock the loop's run method - let it actually run
        // The promise is already resolved, so the loop will stop immediately
        $this->loop->expects($this->once())
            ->method('run')
            ->willReturn(null); // Just return null

        // Mock save
        $this->repo
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Transaction $savedTx) {
                // For withdrawals, Deriv transfer moves to AWAITING_MPESA_DISBURSEMENT, not COMPLETED
                return $savedTx->status()->isAwaitingMpesaDisbursement();
            }));

        // Mock publisher
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with(
                'withdrawals.completed',
                $this->callback(function ($data) use ($txId) {
                    return $data['transaction_id'] === $txId
                        && $data['deriv_txn_id'] === 'D456'
                        && $data['deriv_transfer_id'] === 'T123';
                })
            );

        // Mock logger - ADD CRITICAL MOCK TOO
        $criticalCalled = false;
        $this->logger->expects($this->any())
            ->method('critical')
            ->willReturnCallback(function ($message, $context) use (&$criticalCalled) {
                $criticalCalled = true;
                echo "\n!!! CRITICAL LOG CALLED: " . $message . "\n";
                echo "!!! Context: " . print_r($context, true) . "\n";
            });

        // We expect TWO info() calls:
        // 1. For "calling Deriv paymentagent_withdraw"
        // 2. For "withdrawal completed"
        $infoCallCount = 0;
        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message, $context) use (&$infoCallCount, $txId) {
                $infoCallCount++;
                if ($infoCallCount === 1) {
                    // First call: "calling Deriv paymentagent_withdraw"
                    $this->assertEquals('DerivDebitWorker: calling Deriv paymentagent_withdraw', $message);
                    $this->assertEquals($txId, $context['transaction_id']);
                    $this->assertEquals(1, $context['attempt']);
                    $this->assertEquals(10000, $context['amount_usd_cents']);
                    $this->assertEquals('CR123456', $context['login_id']);
                } elseif ($infoCallCount === 2) {
                    // Second call: "withdrawal completed"
                    $this->assertEquals('DerivDebitWorker: withdrawal completed', $message);
                    $this->assertEquals($txId, $context['transaction_id']);
                    $this->assertEquals('D456', $context['deriv_txn_id']);
                }
            });

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'secret-key-1',
        ]);

        // Check if critical was called
        $this->assertFalse($criticalCalled, 'Critical should not have been called - no exceptions expected');
        
        // For withdrawals, Deriv transfer moves to AWAITING_MPESA_DISBURSEMENT, not COMPLETED
        $this->assertTrue($tx->status()->isAwaitingMpesaDisbursement(), 'Transaction should be awaiting M-Pesa disbursement');
        $this->assertNotNull($tx->derivTransfer());
    }

    /** @test */
    public function it_fails_if_verification_code_missing(): void
    {
        $tx = $this->createWithdrawalTransaction();
        $txId = (string) $tx->id();

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->repo->expects($this->once())
            ->method('save')
            ->with($tx);

        // missing key => fail
        $this->publisher->expects($this->once())
            ->method('publish')
            ->with(
                'withdrawals.failed',
                $this->callback(fn ($data) => 
                    $data['transaction_id'] === $txId
                    && $data['reason'] === 'missing_verification_code'
                )
            );

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            // no secret_key => failure
        ]);

        $this->assertTrue($tx->status()->isFailed());
        $this->assertSame('missing_verification_code', $tx->failureReason());
    }

    /** @test */
    public function it_fails_if_verification_code_expired(): void
    {
        $tx = $this->createWithdrawalTransaction();
        $txId = (string) $tx->id();

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->secretStore
            ->expects($this->once())
            ->method('getAndDelete')
            ->with('expired-key')
            ->willReturn(null); // Code expired

        $this->repo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.failed', $this->callback(function ($data) {
                return $data['reason'] === 'verification_code_missing_or_expired';
            }));

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'expired-key',
        ]);

        $this->assertTrue($tx->status()->isFailed());
        $this->assertSame('verification_code_missing_or_expired', $tx->failureReason());
    }

    /** @test */
    public function it_retries_on_deriv_api_failure_and_eventually_fails(): void
    {
        $tx = $this->createWithdrawalTransaction();
        $txId = (string) $tx->id();

        $this->derivConfig->expects($this->exactly(3))
            ->method('defaultLoginId')
            ->willReturn('PA1234567');

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        // getAndDelete is called only ONCE, before the retry loop
        $this->secretStore->expects($this->once())
            ->method('getAndDelete')
            ->willReturn('ABC123'); // 6 characters

        // simulate Deriv failure with a resolved promise containing failure result
        $result = DerivResult::failure(
            errorMessage: 'Insufficient balance',
            rawResponse: ['error' => 'Insufficient balance']
        );

        $deferred = new Deferred();

        $this->gateway->expects($this->exactly(3))
            ->method('withdraw')
            ->willReturn($deferred->promise());

        $this->loop->expects($this->exactly(3))
            ->method('run')
            ->willReturnCallback(function () use ($deferred, $result) {
                $deferred->resolve($result);
                $this->loop->stop();
            });

        $this->repo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.failed', $this->callback(function ($data) {
                return $data['reason'] === 'deriv_withdrawal_failed';
            }));

        // Expect 4 warnings: 3 for retry attempts + 1 for final failure
        $this->logger->expects($this->exactly(4))
            ->method('warning');

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'foo',
        ]);

        $this->assertTrue($tx->status()->isFailed());
        $this->assertSame('deriv_withdrawal_failed', $tx->failureReason());
    }

    /** @test */
    public function it_retries_on_deriv_api_exception(): void
    {
        $tx = $this->createWithdrawalTransaction();
        $txId = (string) $tx->id();

        $this->derivConfig->expects($this->exactly(3))
            ->method('defaultLoginId')
            ->willReturn('PA1234567');

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        // getAndDelete is called only ONCE, before the retry loop
        $this->secretStore->expects($this->once())
            ->method('getAndDelete')
            ->willReturn('ABC123'); // 6 characters

        // simulate exception in promise
        $deferred = new Deferred();

        $this->gateway->expects($this->exactly(3))
            ->method('withdraw')
            ->willReturn($deferred->promise());

        $this->loop->expects($this->exactly(3))
            ->method('run')
            ->willReturnCallback(function () use ($deferred) {
                $deferred->reject(new \RuntimeException('Network timeout'));
                $this->loop->stop();
            });

        $this->repo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.failed', $this->callback(function ($data) {
                return $data['reason'] === 'deriv_withdrawal_failed'
                    && str_contains($data['message'], 'Network timeout');
            }));

        $this->logger->expects($this->exactly(4)) // 3 warnings for attempts + 1 warning for final failure
            ->method('warning');

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'foo',
        ]);

        $this->assertTrue($tx->status()->isFailed());
    }

    /** @test */
    public function it_skips_already_finalized_transactions(): void
    {
        $tx = $this->createWithdrawalTransaction();
        
        // Complete the transaction first - with proper 6-character code
        $derivTransfer = \PenPay\Domain\Payments\Entity\DerivTransfer::forWithdrawal(
            transactionId: $tx->id(),
            userDerivLoginId: 'CR123456',
            paymentAgentLoginId: 'PA1234567',
            amountUsd: Money::usd(10000),
            derivTransferId: 'T123',
            derivTxnId: 'D456',
            withdrawalVerificationCode: 'ABC123', // 6 characters
            executedAt: new DateTimeImmutable()
        );
        $tx->recordDerivTransfer($derivTransfer);
        
        // Complete the transaction by recording M-Pesa disbursement
        $mpesaDisbursement = \PenPay\Domain\Payments\Entity\MpesaDisbursement::fromArray([
            'transaction_id' => (string) $tx->id(),
            'phone_number' => '+254712345678',
            'amount_kes_cents' => 1000000,
            'conversation_id' => 'conv-123',
            'originator_conversation_id' => 'orig-123',
            'mpesa_receipt_number' => 'MP123456',
            'status' => 'COMPLETED',
            'result_code' => '0',
            'result_description' => 'Success',
            'raw_payload' => [],
            'completed_at' => (new \DateTimeImmutable())->format('c')
        ]);
        $tx->recordMpesaDisbursement($mpesaDisbursement);
        
        $txId = (string) $tx->id();

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        // Should not call gateway, secretStore, or publisher
        $this->secretStore->expects($this->never())->method('getAndDelete');
        $this->gateway->expects($this->never())->method('withdraw');
        $this->publisher->expects($this->never())->method('publish');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('DerivDebitWorker: already finalized — skipping', $this->callback(function ($context) use ($txId) {
                return $context['transaction_id'] === $txId
                    && $context['status'] === 'COMPLETED';
            }));

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'foo',
        ]);

        $this->assertTrue($tx->isFinalized());
    }

    /** @test */
    public function it_fails_when_missing_deriv_data(): void
    {
        // We can't pass null to initiateWithdrawal, so we need to create the transaction
        // and then use reflection to set the userDerivLoginId to null
        $tx = Transaction::initiateWithdrawal(
            id: TransactionId::generate(),
            userId: 'U123',
            amountUsd: Money::usd(10000),
            lockedRate: LockedRate::lock(100.0),
            idempotencyKey: IdempotencyKey::fromHeader('withdrawal-test-' . uniqid()),
            userDerivLoginId: 'CR123456', // We'll set this to null using reflection
            withdrawalVerificationCode: 'ABC123'
        );
        
        // Transition through withdrawal states first
        $tx->markDerivWithdrawalInitiated();
        $tx->markAwaitingDerivConfirmation();
        
        // Use reflection to set userDerivLoginId to null
        $reflection = new \ReflectionClass($tx);
        $property = $reflection->getProperty('userDerivLoginId');
        $property->setAccessible(true);
        $property->setValue($tx, null);
        
        $txId = (string) $tx->id();

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->repo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.failed', $this->callback(function ($data) {
                return $data['reason'] === 'missing_deriv_data';
            }));

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'foo',
        ]);

        $this->assertTrue($tx->status()->isFailed());
        $this->assertSame('missing_deriv_data', $tx->failureReason());
    }

    /** @test */
    public function it_fails_when_not_a_withdrawal_transaction(): void
    {
        // Create a deposit transaction instead
        $tx = Transaction::initiateDeposit(
            id: TransactionId::generate(),
            userId: 'U123',
            amountUsd: Money::usd(10000),
            lockedRate: LockedRate::lock(100.0),
            idempotencyKey: IdempotencyKey::fromHeader('deposit-test-' . uniqid()),
            userDerivLoginId: 'CR123456'
        );
        
        // Transition deposit through appropriate states
        $tx->markStkPushInitiated();
        $tx->markAwaitingMpesaCallback();
        
        $txId = (string) $tx->id();

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->repo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.failed', $this->callback(function ($data) {
                return $data['reason'] === 'invalid_transaction_type';
            }));

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'foo',
        ]);

        $this->assertTrue($tx->status()->isFailed());
        $this->assertSame('invalid_transaction_type', $tx->failureReason());
    }

    /** @test */
    public function it_handles_missing_transaction_id_in_payload(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('DerivDebitWorker: missing or invalid transaction_id', []);

        // No other methods should be called
        $this->repo->expects($this->never())->method('getById');

        $worker = $this->createWorker();
        $worker->handle([]);
    }

    /** @test */
    public function it_handles_invalid_transaction_id_format(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('DerivDebitWorker: invalid transaction_id format', ['transaction_id' => 'invalid-id']);

        $this->repo->expects($this->never())->method('getById');

        $worker = $this->createWorker();
        $worker->handle(['transaction_id' => 'invalid-id']);
    }

    /** @test */
    public function it_handles_transaction_not_found(): void
    {
        $txId = TransactionId::generate();

        $this->repo->expects($this->once())
            ->method('getById')
            ->willThrowException(new \RuntimeException('Transaction not found'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('DerivDebitWorker: failed to load transaction', $this->callback(function ($context) use ($txId) {
                return $context['transaction_id'] === (string)$txId
                    && str_contains($context['error'], 'Transaction not found');
            }));

        $worker = $this->createWorker();
        $worker->handle(['transaction_id' => (string)$txId]);
    }

    /** @test */
    public function it_logs_each_retry_attempt(): void
    {
        $tx = $this->createWithdrawalTransaction();
        $txId = (string) $tx->id();

        $this->derivConfig->expects($this->exactly(3))
            ->method('defaultLoginId')
            ->willReturn('PA1234567');

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        // getAndDelete is called only ONCE, before the retry loop
        $this->secretStore->expects($this->once())
            ->method('getAndDelete')
            ->willReturn('ABC123'); // 6 characters

        // simulate exception in promise - use Deferred
        $deferred = new Deferred();

        $this->gateway->expects($this->exactly(3))
            ->method('withdraw')
            ->willReturn($deferred->promise());

        $this->loop->expects($this->exactly(3))
            ->method('run')
            ->willReturnCallback(function () use ($deferred) {
                $deferred->reject(new \RuntimeException('Network error'));
                $this->loop->stop();
            });

        $infoCallCount = 0;
        $this->logger->expects($this->exactly(3))
            ->method('info')
            ->willReturnCallback(function ($message, $context) use (&$infoCallCount) {
                $infoCallCount++;
                $this->assertStringContainsString('calling Deriv paymentagent_withdraw', $message);
                $this->assertSame($infoCallCount, $context['attempt']);
            });

        // Expect 4 warnings: 3 for retry attempts + 1 for final failure
        $warningCallCount = 0;
        $this->logger->expects($this->exactly(4))
            ->method('warning')
            ->willReturnCallback(function ($message) use (&$warningCallCount) {
                $warningCallCount++;
                if ($warningCallCount <= 3) {
                    $this->assertStringContainsString('attempt failed', $message);
                } else {
                    $this->assertStringContainsString('withdrawal failed', $message);
                }
            });

        $this->repo->expects($this->once())->method('save');
        $this->publisher->expects($this->once())->method('publish');

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'foo',
        ]);
    }
}