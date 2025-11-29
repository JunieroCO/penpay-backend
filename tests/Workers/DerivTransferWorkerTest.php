<?php
declare(strict_types=1);

namespace PenPay\Tests\Workers;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Entity\DerivTransfer;
use PenPay\Domain\Payments\Entity\DerivTransferResult;
use PenPay\Domain\Payments\Entity\MpesaRequest;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Infrastructure\Deriv\Deposit\DerivDepositGatewayInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Workers\DerivTransferWorker;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;

final class DerivTransferWorkerTest extends TestCase
{
    private TransactionRepositoryInterface&MockObject $txRepo;
    private DerivDepositGatewayInterface&MockObject $derivGateway;
    private RedisStreamPublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;
    private LoopInterface&MockObject $loop;
    private DerivTransferWorker $worker;

    protected function setUp(): void
    {
        $this->txRepo = $this->createMock(TransactionRepositoryInterface::class);
        $this->derivGateway = $this->createMock(DerivDepositGatewayInterface::class);
        $this->publisher = $this->createMock(RedisStreamPublisherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->loop = $this->createMock(LoopInterface::class);

        $this->worker = new DerivTransferWorker(
            $this->txRepo,
            $this->derivGateway,
            $this->publisher,
            $this->logger,
            $this->loop
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

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);
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

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);
    }

    /** @test */
    public function it_fails_when_missing_deriv_credentials(): void
    {
        $tx = $this->createMpesaConfirmedTransaction();

        $ref = new \ReflectionClass($tx);
        $ref->getProperty('userDerivLoginId')->setValue($tx, null);
        $ref->getProperty('paymentAgentToken')->setValue($tx, null);

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', $this->callback(fn($p) => $p['reason'] === 'missing_deriv_data'));

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);
    }

    /** @test */
    public function it_successfully_completes_deriv_transfer(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->getId())
            ->willReturn($tx);

        $successResult = DerivTransferResult::success(
            transferId: 'deriv-transfer-123',
            txnId: 'deriv-txn-456',
            amountUsd: 10.5,
            rawResponse: ['status' => 'ok']
        );

        $promise = new Promise(function ($resolve) use ($successResult) {
            $resolve($successResult);
        });

        $this->derivGateway->expects($this->once())
            ->method('deposit')
            ->willReturn($promise);

        $this->loop->expects($this->once())
            ->method('run')
            ->willReturnCallback(function () use ($promise) {
                $promise->then(fn($r) => $r);
            });

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.completed', $this->anything());

        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);

        $this->assertTrue($tx->getStatus()->isCompleted());
    }

    /** @test */
    public function it_retries_and_eventually_fails_on_deriv_errors(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        // Create rejected promises for all 3 attempts
        $promise = new Promise(function ($resolve, $reject) {
            $reject(new \RuntimeException('Deriv API timeout'));
        });

        $this->derivGateway->expects($this->exactly(3))
            ->method('deposit')
            ->willReturn($promise);

        $this->loop->expects($this->exactly(3))
            ->method('run')
            ->willReturnCallback(function () use ($promise) {
                $promise->then(null, fn($e) => $e);
            });

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

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);

        $this->assertTrue($tx->getStatus()->isFailed());
    }

    /** @test */
    public function it_fails_when_deriv_returns_failure_result(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $failureResult = DerivTransferResult::failure(
            errorMessage: 'Insufficient balance',
            rawResponse: ['error' => ['code' => 'InsufficientBalance']]
        );

        $promise = new Promise(function ($resolve) use ($failureResult) {
            $resolve($failureResult);
        });

        $this->derivGateway->expects($this->exactly(3))
            ->method('deposit')
            ->willReturn($promise);

        $this->loop->expects($this->exactly(3))
            ->method('run')
            ->willReturnCallback(function () use ($promise) {
                $promise->then(fn($r) => $r);
            });

        $this->txRepo->expects($this->once())
            ->method('save');

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', $this->anything());

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);

        $this->assertTrue($tx->getStatus()->isFailed());
    }

    /** @test */
    public function it_includes_metadata_in_deriv_call(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $successResult = DerivTransferResult::success('T1', 'D1', 10.5, []);
        $promise = new Promise(function ($resolve) use ($successResult) {
            $resolve($successResult);
        });

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
            ->method('run')
            ->willReturnCallback(function () use ($promise) {
                $promise->then(fn($r) => $r);
            });

        $this->txRepo->expects($this->once())->method('save');
        $this->publisher->expects($this->once())->method('publish');

        $this->worker->handle([
            'transaction_id' => (string)$tx->getId(),
            'mpesa_receipt' => 'RKL9ABCDEF',
            'phone' => '254712345678',
        ]);
    }

    /** @test */
    public function it_logs_each_retry_attempt(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $promise = new Promise(function ($resolve, $reject) {
            $reject(new \RuntimeException('Network error'));
        });

        $this->derivGateway->expects($this->exactly(3))
            ->method('deposit')
            ->willReturn($promise);

        $this->loop->expects($this->exactly(3))
            ->method('run')
            ->willReturnCallback(function () use ($promise) {
                $promise->then(null, fn($e) => $e);
            });

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

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);
    }

    // === HELPERS ===
    private function createPendingTransactionWithDerivData(): Transaction
    {
        return Transaction::initiateDeposit(
            id: TransactionId::generate(),
            amountKes: Money::kes(100000),
            idempotencyKey: \PenPay\Domain\Payments\ValueObject\IdempotencyKey::generate(),
            userDerivLoginId: 'CR123456',
            paymentAgentToken: 'agent_token_xyz',
            amountUsd: 10.5
        );
    }

    private function createMpesaConfirmedTransaction(): Transaction
    {
        $tx = $this->createPendingTransactionWithDerivData();

        $mpesaRequest = new MpesaRequest(
            transactionId: $tx->getId(),
            phoneNumber: '254712345678',
            amountKes: Money::kes(100000),
            merchantRequestId: 'merchant-test-123',
            checkoutRequestId: 'ws_CO_20250425123456789',
            mpesaReceiptNumber: 'RKL9ABCDEF',
            callbackReceivedAt: new DateTimeImmutable(),
            initiatedAt: new DateTimeImmutable('2025-04-25 10:00:00')
        );

        $tx->recordMpesaCallback($mpesaRequest);
        return $tx;
    }

    private function createMpesaConfirmedTransactionWithDerivData(): Transaction
    {
        return $this->createMpesaConfirmedTransaction();
    }

    private function createFinalizedTransaction(): Transaction
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $ref = new \ReflectionClass($tx);
        $ref->getProperty('status')->setValue($tx, TransactionStatus::COMPLETED);

        $derivTransfer = DerivTransfer::success(
            transactionId: $tx->getId(),
            derivAccountId: 'CR123456',
            amountUsd: Money::usd(1050),
            derivTransferId: 'deriv-transfer-123',
            derivTxnId: 'deriv-txn-456',
            executedAt: new DateTimeImmutable(),
            rawResponse: ['status' => 'ok']
        );

        $ref->getProperty('derivTransfer')->setValue($tx, $derivTransfer);

        return $tx;
    }
}