<?php

declare(strict_types=1);

namespace PenPay\Tests\Workers\Deposit;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Entity\DerivTransfer;
use PenPay\Domain\Payments\Entity\DerivResult;
use PenPay\Domain\Payments\Entity\MpesaRequest;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use PenPay\Infrastructure\Deriv\Deposit\DerivDepositGatewayInterface;
use PenPay\Infrastructure\Deriv\DerivConfig;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Workers\Deposit\DerivTransferWorker;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final class DerivTransferWorkerTest extends TestCase
{
    private TransactionRepositoryInterface&MockObject $txRepo;
    private DerivDepositGatewayInterface&MockObject $derivGateway;
    private RedisStreamPublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;
    private LoopInterface&MockObject $loop;
    private DerivConfig&MockObject $derivConfig;
    private DerivTransferWorker $worker;

    protected function setUp(): void
    {
        $this->txRepo = $this->createMock(TransactionRepositoryInterface::class);
        $this->derivGateway = $this->createMock(DerivDepositGatewayInterface::class);
        $this->publisher = $this->createMock(RedisStreamPublisherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->loop = $this->createMock(LoopInterface::class);
        $this->derivConfig = $this->createMock(DerivConfig::class);

        $this->worker = new DerivTransferWorker(
            $this->txRepo,
            $this->derivGateway,
            $this->publisher,
            $this->logger,
            $this->loop,
            $this->derivConfig
        );
    }

    /** @test */
    public function it_skips_when_missing_transaction_id(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('missing or invalid transaction_id'));

        $this->worker->handle([]);
    }

    /** @test */
    public function it_skips_when_transaction_not_found(): void
    {
        $txId = TransactionId::generate();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($txId)
            ->willThrowException(new \RuntimeException('Transaction not found'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('failed to load transaction'));

        $this->worker->handle(['transaction_id' => (string)$txId]);
    }

    /** @test */
    public function it_skips_when_transaction_already_finalized(): void
    {
        $tx = $this->createFinalizedTransaction();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('already finalized'));

        $this->worker->handle(['transaction_id' => (string)$tx->id()]);
    }

    /** @test */
    public function it_warns_when_no_mpesa_callback_received(): void
    {
        $tx = $this->createPendingTransactionWithDerivData();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('no M-Pesa callback yet'));

        $this->worker->handle(['transaction_id' => (string)$tx->id()]);
    }

    /** @test */
    public function it_fails_when_missing_deriv_credentials(): void
    {
        $tx = $this->createMpesaConfirmedTransaction();

        // Use reflection to remove deriv login ID (simulating missing data)
        $ref = new \ReflectionClass($tx);
        $prop = $ref->getProperty('userDerivLoginId');
        $prop->setAccessible(true);
        $prop->setValue($tx, null);

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', $this->callback(fn($p) => $p['reason'] === 'missing_deriv_data'));

        $this->worker->handle(['transaction_id' => (string)$tx->id()]);
    }

    /** @test */
    public function it_successfully_completes_deriv_transfer(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $this->derivConfig->expects($this->once())
            ->method('agentToken')
            ->willReturn('agent_token_xyz');

        $this->derivConfig->expects($this->once())
            ->method('defaultLoginId')
            ->willReturn('PA1234567');

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->id())
            ->willReturn($tx);

        $successResult = DerivResult::success(
            transferId: 'deriv-transfer-123',
            txnId: 'deriv-txn-456',
            amountUsd: Money::usd(1050), // $10.50
            rawResponse: ['status' => 'ok']
        );

        // Create fresh promise that resolves immediately
        $promise = $this->createResolvedPromise($successResult);

        $this->derivGateway->expects($this->once())
            ->method('deposit')
            ->willReturn($promise);

        // Mock loop->run() to simulate promise resolution
        $this->loop->expects($this->once())
            ->method('run');

        // Mock loop->stop() being called when promise resolves
        $this->loop->expects($this->once())
            ->method('stop');

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.completed', $this->anything());

        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $this->worker->handle(['transaction_id' => (string)$tx->id()]);

        $this->assertTrue($tx->status()->isCompleted());
    }

    /** @test */
    public function it_retries_and_eventually_fails_on_deriv_errors(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $this->derivConfig->expects($this->exactly(3))
            ->method('agentToken')
            ->willReturn('agent_token_xyz');

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        // Create rejected promise that gets reused 3 times
        $rejectedPromise = $this->createRejectedPromise(new \RuntimeException('Deriv API timeout'));

        $this->derivGateway->expects($this->exactly(3))
            ->method('deposit')
            ->willReturn($rejectedPromise);

        $this->loop->expects($this->exactly(3))
            ->method('run');

        $this->loop->expects($this->exactly(3))
            ->method('stop');

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', $this->callback(function ($data) {
                return $data['reason'] === 'deriv_transfer_failed'
                    && str_contains($data['message'], 'Deriv API timeout');
            }));

        // Expect 4 warnings: 3 for retry attempts + 1 for final failure
        $this->logger->expects($this->exactly(4))
            ->method('warning');

        $this->worker->handle(['transaction_id' => (string)$tx->id()]);

        $this->assertTrue($tx->status()->isFailed());
    }

    /** @test */
    public function it_fails_when_deriv_returns_failure_result(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $this->derivConfig->expects($this->exactly(3))
            ->method('agentToken')
            ->willReturn('agent_token_xyz');

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $failureResult = DerivResult::failure(
            errorMessage: 'Insufficient balance',
            rawResponse: ['error' => ['code' => 'InsufficientBalance']]
        );

        // Create resolved promise with failure result
        $promise = $this->createResolvedPromise($failureResult);

        $this->derivGateway->expects($this->exactly(3))
            ->method('deposit')
            ->willReturn($promise);

        $this->loop->expects($this->exactly(3))
            ->method('run');

        $this->loop->expects($this->exactly(3))
            ->method('stop');

        $this->txRepo->expects($this->once())
            ->method('save');

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', $this->anything());

        $this->worker->handle(['transaction_id' => (string)$tx->id()]);

        $this->assertTrue($tx->status()->isFailed());
    }

    /** @test */
    public function it_includes_metadata_in_deriv_call(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $this->derivConfig->expects($this->once())
            ->method('agentToken')
            ->willReturn('agent_token_xyz');

        $this->derivConfig->expects($this->once())
            ->method('defaultLoginId')
            ->willReturn('PA1234567');

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $successResult = DerivResult::success(
            transferId: 'T1',
            txnId: 'D1',
            amountUsd: Money::usd(1050),
            rawResponse: []
        );

        // Create fresh resolved promise
        $promise = $this->createResolvedPromise($successResult);

        $this->derivGateway->expects($this->once())
            ->method('deposit')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function ($metadata) {
                    return $metadata['mpesa_receipt'] === 'RKL9ABCDEF'
                        && $metadata['phone'] === '254712345678';
                })
            )
            ->willReturn($promise);

        $this->loop->expects($this->once())
            ->method('run');

        $this->loop->expects($this->once())
            ->method('stop');

        $this->txRepo->expects($this->once())
            ->method('save');

        $this->publisher->expects($this->once())
            ->method('publish');

        $this->worker->handle([
            'transaction_id' => (string)$tx->id(),
            'mpesa_receipt' => 'RKL9ABCDEF',
            'phone' => '254712345678',
        ]);
    }

    /** @test */
    public function it_logs_each_retry_attempt(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $this->derivConfig->expects($this->exactly(3))
            ->method('agentToken')
            ->willReturn('agent_token_xyz');

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        // Create rejected promise
        $rejectedPromise = $this->createRejectedPromise(new \RuntimeException('Network error'));

        $this->derivGateway->expects($this->exactly(3))
            ->method('deposit')
            ->willReturn($rejectedPromise);

        $this->loop->expects($this->exactly(3))
            ->method('run');

        $this->loop->expects($this->exactly(3))
            ->method('stop');

        $infoCallCount = 0;
        $this->logger->expects($this->exactly(3))
            ->method('info')
            ->willReturnCallback(function ($message, $context) use (&$infoCallCount) {
                $infoCallCount++;
                $this->assertStringContainsString('calling Deriv payment_agent_deposit', $message);
                $this->assertSame($infoCallCount, $context['attempt']);
            });

        // Expect 4 warnings: 3 for retry attempts + 1 for final failure in failTransaction
        $warningCallCount = 0;
        $this->logger->expects($this->exactly(4))
            ->method('warning')
            ->willReturnCallback(function ($message) use (&$warningCallCount) {
                $warningCallCount++;
                if ($warningCallCount <= 3) {
                    $this->assertStringContainsString('attempt failed', $message);
                } else {
                    $this->assertStringContainsString('deposit failed', $message);
                }
            });

        $this->txRepo->expects($this->once())->method('save');
        $this->publisher->expects($this->once())->method('publish');

        $this->worker->handle(['transaction_id' => (string)$tx->id()]);
    }

    // ===================================================================
    // HELPER METHODS
    // ===================================================================

    /**
     * Create a promise that resolves immediately with the given value
     */
    private function createResolvedPromise($value): PromiseInterface
    {
        $deferred = new Deferred();
        $promise = $deferred->promise();
        
        // Immediately resolve with the value
        $deferred->resolve($value);
        
        return $promise;
    }

    /**
     * Create a promise that rejects immediately with the given error
     */
    private function createRejectedPromise(\Throwable $error): PromiseInterface
    {
        $deferred = new Deferred();
        $promise = $deferred->promise();
        
        // Immediately reject with the error
        $deferred->reject($error);
        
        return $promise;
    }

    private function createPendingTransactionWithDerivData(): Transaction
    {
        return Transaction::initiateDeposit(
            id: TransactionId::generate(),
            userId: 'user-123',
            amountUsd: Money::usd(1050), // $10.50
            lockedRate: LockedRate::lock(100.0), // 1 USD = 100 KES
            idempotencyKey: IdempotencyKey::fromHeader('deposit-test-' . uniqid()),
            userDerivLoginId: 'CR123456'
        );
    }

    private function createMpesaConfirmedTransaction(): Transaction
    {
        $tx = $this->createPendingTransactionWithDerivData();

        // Transition through proper states
        $tx->markStkPushInitiated(); // PENDING → PROCESSING
        $tx->markAwaitingMpesaCallback(); // PROCESSING → AWAITING_MPESA_CALLBACK

        // Now we can record M-Pesa callback
        $mpesaRequest = MpesaRequest::fromCallback(
            checkoutRequestId: 'ws_CO_20250425123456789',
            mpesaReceiptNumber: 'RKL9ABCDEF',
            phoneNumber: PhoneNumber::fromKenyan('254712345678'),
            amountKes: Money::kes(105000), // KES equivalent of $10.50 at rate 100
            callbackReceivedAt: new DateTimeImmutable(),
            merchantRequestId: 'merchant-test-123'
        );

        $tx->recordMpesaCallback($mpesaRequest); // AWAITING_MPESA_CALLBACK → AWAITING_DERIV_CONFIRMATION
        return $tx;
    }

    private function createMpesaConfirmedTransactionWithDerivData(): Transaction
    {
        return $this->createMpesaConfirmedTransaction();
    }

    private function createFinalizedTransaction(): Transaction
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        // Create a deriv transfer to complete the transaction
        $derivTransfer = DerivTransfer::forDeposit(
            transactionId: $tx->id(),
            paymentAgentLoginId: 'PA1234567',
            userDerivLoginId: 'CR123456',
            amountUsd: Money::usd(1050),
            derivTransferId: 'deriv-transfer-123',
            derivTxnId: 'deriv-txn-456',
            executedAt: new DateTimeImmutable(),
            rawResponse: ['status' => 'ok']
        );

        $tx->recordDerivTransfer($derivTransfer);
        return $tx;
    }
}