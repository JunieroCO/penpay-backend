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
use PenPay\Infrastructure\Deriv\DerivGatewayInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Workers\DerivTransferWorker;
use Psr\Log\LoggerInterface;

final class DerivTransferWorkerTest extends TestCase
{
    private TransactionRepositoryInterface&MockObject $txRepo;
    private DerivGatewayInterface&MockObject $derivGateway;
    private RedisStreamPublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;
    private DerivTransferWorker $worker;

    protected function setUp(): void
    {
        $this->txRepo = $this->createMock(TransactionRepositoryInterface::class);
        $this->derivGateway = $this->createMock(DerivGatewayInterface::class);
        $this->publisher = $this->createMock(RedisStreamPublisherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->worker = new DerivTransferWorker(
            $this->txRepo,
            $this->derivGateway,
            $this->publisher,
            $this->logger
        );
    }

    public function test_skips_when_missing_transaction_id(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('missing or invalid transaction_id'));

        $this->worker->handle([]);
    }

    public function test_skips_when_transaction_not_found(): void
    {
        $txId = TransactionId::generate();

        $this->txRepo->method('getById')
            ->with($txId)
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('transaction not found'));

        $this->worker->handle(['transaction_id' => (string)$txId]);
    }

    public function test_skips_when_transaction_already_finalized(): void
    {
        $tx = $this->createFinalizedTransaction();

        $this->txRepo->method('getById')->willReturn($tx);
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('already finalized'));

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);
    }

    public function test_fails_when_no_mpesa_callback_received(): void
    {
        $tx = $this->createPendingTransactionWithDerivData();

        $this->txRepo->method('getById')->willReturn($tx);
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('no M-Pesa callback yet'));

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);
    }

    public function test_fails_when_missing_deriv_credentials(): void
    {
        $tx = $this->createMpesaConfirmedTransaction();

        $ref = new \ReflectionClass($tx);
        $ref->getProperty('userDerivLoginId')->setValue($tx, null);
        $ref->getProperty('paymentAgentToken')->setValue($tx, null);

        $this->txRepo->method('getById')->willReturn($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', $this->callback(fn($p) => $p['reason'] === 'missing_deriv_data'));

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);
    }

    public function test_successful_deriv_transfer_completes_transaction(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        // Verify initial state
        $this->assertTrue($tx->getStatus()->isMpesaConfirmed(), 'Transaction should be MPESA_CONFIRMED initially');
        $this->assertTrue($tx->hasDerivCredentials(), 'Transaction should have Deriv credentials');
        $this->assertTrue($tx->hasUsdAmount(), 'Transaction should have USD amount');

        // Configure mock expectations IN ORDER
        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->getId())
            ->willReturn($tx);

        $saveCalled = false;
        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx)
            ->willReturnCallback(function () use (&$saveCalled) {
                $saveCalled = true;
            });

        $successResult = DerivTransferResult::success(
            transferId: 'deriv-transfer-123',
            txnId: 'deriv-txn-456',
            amountUsd: 10.5,
            rawResponse: ['status' => 'ok']
        );
        
        $this->derivGateway->method('paymentAgentDeposit')
            ->willReturn($successResult);

        // Allow any publisher calls
        $this->publisher->expects($this->any())->method('publish');

        // Allow any logger calls (logger methods return void)
        $this->logger->expects($this->any())->method('info');
        $this->logger->expects($this->any())->method('warning');
        $this->logger->expects($this->any())->method('error');
        
        // Allow any logger calls (logger methods return void)
        // Note: critical should not be called for successful completion
        $this->logger->expects($this->any())->method('critical');

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);

        // The transaction should be saved (mock expectation will fail if not)
        // and the status should be completed
        $this->assertTrue($tx->getStatus()->isCompleted(), sprintf(
            'Transaction status should be completed after successful Deriv transfer. Current status: %s. Save called: %s',
            $tx->getStatus()->value,
            $saveCalled ? 'yes' : 'no'
        ));
    }

    public function test_deriv_failure_marks_transaction_failed(): void
    {
        $tx = $this->createMpesaConfirmedTransactionWithDerivData();

        $this->txRepo->method('getById')->willReturn($tx);
        $this->txRepo->expects($this->once())->method('save');

        $this->derivGateway->expects($this->exactly(3))
            ->method('paymentAgentDeposit')
            ->willThrowException(new \RuntimeException('Deriv API timeout'));

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', $this->anything());

        $this->worker->handle(['transaction_id' => (string)$tx->getId()]);

        $this->assertTrue($tx->getStatus()->isFailed());
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
        $tx = $this->createPendingTransactionWithDerivData(); // ← Already has Deriv data from factory!

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

        // NO REFLECTION NEEDED — initiateDeposit already set:
        // userDerivLoginId = 'CR123456'
        // paymentAgentToken = 'agent_token_xyz'
        // amountUsd = 10.5

        return $tx;
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