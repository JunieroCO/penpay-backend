<?php
declare(strict_types=1);

namespace PenPay\Tests\Workers\Deposit;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Entity\MpesaRequest;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Workers\Deposit\MpesaCallbackWorker;
use Psr\Log\LoggerInterface;

final class MpesaCallbackWorkerTest extends TestCase
{
    private TransactionRepositoryInterface&MockObject $txRepo;
    private RedisStreamPublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;
    private MpesaCallbackWorker $worker;

    protected function setUp(): void
    {
        $this->txRepo = $this->createMock(TransactionRepositoryInterface::class);
        $this->publisher = $this->createMock(RedisStreamPublisherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->worker = new MpesaCallbackWorker(
            $this->txRepo,
            $this->publisher,
            $this->logger
        );
    }

    /** @test */
    public function it_logs_error_when_transaction_id_invalid(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('invalid transaction ID format'));

        $this->worker->handle([
            'transaction_id' => 'invalid-uuid',
        ]);
    }

    /** @test */
    public function it_logs_error_when_transaction_not_found(): void
    {
        $txId = TransactionId::generate();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($txId)
            ->willThrowException(new \Exception('Transaction not found'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('transaction not found or error loading'));

        $this->worker->handle([
            'transaction_id' => (string)$txId,
        ]);
    }

    /** @test */
    public function it_ignores_callback_when_transaction_already_completed(): void
    {
        $tx = $this->createCompletedTransaction();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->id())
            ->willReturn($tx);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Callback ignored: transaction already finalized'));

        // Should NOT save or publish
        $this->txRepo->expects($this->never())->method('save');
        $this->publisher->expects($this->never())->method('publish');

        $this->worker->handle([
            'transaction_id' => (string)$tx->id(),
            'status' => 'success',
            'checkout_request_id' => 'test-request-id',
            'mpesa_receipt' => 'RKL123456',
            'phone' => '254712345678',
            'amount_kes_cents' => 150000,
        ]);
    }

    /** @test */
    public function it_ignores_callback_when_transaction_already_failed(): void
    {
        $tx = $this->createFailedTransaction();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->id())
            ->willReturn($tx);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Callback ignored: transaction already finalized'));

        // Should NOT save or publish
        $this->txRepo->expects($this->never())->method('save');
        $this->publisher->expects($this->never())->method('publish');

        $this->worker->handle([
            'transaction_id' => (string)$tx->id(),
            'status' => 'success',
            'checkout_request_id' => 'test-request-id',
            'mpesa_receipt' => 'RKL123456',
            'phone' => '254712345678',
            'amount_kes_cents' => 150000,
        ]);
    }

    /** @test */
    public function it_processes_successful_mpesa_callback(): void
    {
        $tx = $this->createAwaitingMpesaCallbackTransaction();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->id())
            ->willReturn($tx);

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.mpesa_confirmed', [
                'transaction_id' => (string)$tx->id(),
                'mpesa_receipt' => 'RKL9ABCDEF',
                'amount_kes_cents' => 150000,
                'phone' => '254712345678',
            ]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('M-Pesa deposit confirmed'));

        $this->worker->handle([
            'transaction_id' => (string)$tx->id(),
            'status' => 'success',
            'checkout_request_id' => 'ws_CO_20250425123456789',
            'mpesa_receipt' => 'RKL9ABCDEF',
            'phone' => '254712345678',
            'amount_kes_cents' => 150000,
        ]);

        // Verify transaction state changed
        $this->assertTrue($tx->hasMpesaCallback());
        $this->assertTrue($tx->status()->isAwaitingDerivConfirmation());
        $this->assertNotNull($tx->mpesaRequest());
        $this->assertSame('RKL9ABCDEF', $tx->mpesaRequest()->mpesaReceiptNumber);
    }

    /** @test */
    public function it_fails_transaction_on_unsuccessful_mpesa_callback(): void
    {
        $tx = $this->createAwaitingMpesaCallbackTransaction();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->id())
            ->willReturn($tx);

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', [
                'transaction_id' => (string)$tx->id(),
                'reason' => 'user_cancelled_or_timeout',
                'result_code' => '1032',
            ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('M-Pesa deposit failed'));

        $this->worker->handle([
            'transaction_id' => (string)$tx->id(),
            'status' => 'failed',
            'result_code' => '1032', // User cancelled
            'checkout_request_id' => 'ws_CO_20250425123456789',
            'phone' => '254712345678',
            'amount_kes_cents' => 150000,
        ]);

        // Verify transaction failed
        $this->assertTrue($tx->status()->isFailed());
        $this->assertSame('mpesa_user_cancelled', $tx->failureReason());
    }

    /** @test */
    public function it_fails_transaction_on_cancelled_status(): void
    {
        $tx = $this->createAwaitingMpesaCallbackTransaction();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->id())
            ->willReturn($tx);

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', [
                'transaction_id' => (string)$tx->id(),
                'reason' => 'user_cancelled_or_timeout',
                'result_code' => '1',
            ]);

        $this->worker->handle([
            'transaction_id' => (string)$tx->id(),
            'status' => 'cancelled',
            'result_code' => '1',
            'checkout_request_id' => 'ws_CO_20250425123456789',
            'phone' => '254712345678',
            'amount_kes_cents' => 150000,
        ]);

        $this->assertTrue($tx->status()->isFailed());
    }

    /** @test */
    public function it_fails_transaction_on_timeout_status(): void
    {
        $tx = $this->createAwaitingMpesaCallbackTransaction();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->id())
            ->willReturn($tx);

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', [
                'transaction_id' => (string)$tx->id(),
                'reason' => 'user_cancelled_or_timeout',
                'result_code' => '1037',
            ]);

        $this->worker->handle([
            'transaction_id' => (string)$tx->id(),
            'status' => 'timeout',
            'result_code' => '1037',
            'checkout_request_id' => 'ws_CO_20250425123456789',
            'phone' => '254712345678',
            'amount_kes_cents' => 150000,
        ]);

        $this->assertTrue($tx->status()->isFailed());
    }

    /** @test */
    public function it_handles_different_phone_formats(): void
    {
        $tx = $this->createAwaitingMpesaCallbackTransaction();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->id())
            ->willReturn($tx);

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish');

        // Test with different phone formats
        $this->worker->handle([
            'transaction_id' => (string)$tx->id(),
            'status' => 'success',
            'checkout_request_id' => 'ws_CO_20250425123456789',
            'mpesa_receipt' => 'RKL9ABCDEF',
            'phone' => '0712345678', // Local format without country code
            'amount_kes_cents' => 150000,
        ]);

        $this->assertTrue($tx->hasMpesaCallback());
    }

    /** @test */
    public function it_handles_zero_amount(): void
    {
        $tx = $this->createAwaitingMpesaCallbackTransaction();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->id())
            ->willReturn($tx);

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish');

        // Edge case: zero amount (should be valid for Money object)
        $this->worker->handle([
            'transaction_id' => (string)$tx->id(),
            'status' => 'success',
            'checkout_request_id' => 'ws_CO_20250425123456789',
            'mpesa_receipt' => 'RKL9ABCDEF',
            'phone' => '254712345678',
            'amount_kes_cents' => 0,
        ]);

        $this->assertTrue($tx->hasMpesaCallback());
        $this->assertSame(0, $tx->mpesaRequest()->amountKes->cents);
    }

    /** @test */
    public function it_handles_large_amount(): void
    {
        $tx = $this->createAwaitingMpesaCallbackTransaction();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->id())
            ->willReturn($tx);

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish');

        // Edge case: large amount
        $this->worker->handle([
            'transaction_id' => (string)$tx->id(),
            'status' => 'success',
            'checkout_request_id' => 'ws_CO_20250425123456789',
            'mpesa_receipt' => 'RKL9ABCDEF',
            'phone' => '254712345678',
            'amount_kes_cents' => 15000000, // 150,000 KES
        ]);

        $this->assertTrue($tx->hasMpesaCallback());
        $this->assertSame(15000000, $tx->mpesaRequest()->amountKes->cents);
    }

    /** @test */
    public function it_handles_missing_optional_fields_on_failure(): void
    {
        $tx = $this->createAwaitingMpesaCallbackTransaction();

        $this->txRepo->expects($this->once())
            ->method('getById')
            ->with($tx->id())
            ->willReturn($tx);

        $this->txRepo->expects($this->once())
            ->method('save')
            ->with($tx);

        // Failure doesn't need mpesa_receipt or amount
        $this->worker->handle([
            'transaction_id' => (string)$tx->id(),
            'status' => 'failed',
            'result_code' => '1032',
            'checkout_request_id' => 'ws_CO_20250425123456789',
        ]);

        $this->assertTrue($tx->status()->isFailed());
    }

    // === HELPER METHODS ===

    private function createAwaitingMpesaCallbackTransaction(): Transaction
    {
        $tx = Transaction::initiateDeposit(
            id: TransactionId::generate(),
            userId: 'user-123',
            amountUsd: Money::usd(1500), // $15.00
            lockedRate: LockedRate::lock(100.0), // 1 USD = 100 KES
            idempotencyKey: IdempotencyKey::fromHeader('deposit-test-' . uniqid()),
            userDerivLoginId: 'CR123456'
        );

        // Transition to AWAITING_MPESA_CALLBACK state
        $tx->markStkPushInitiated(); // PENDING → PROCESSING
        $tx->markAwaitingMpesaCallback(); // PROCESSING → AWAITING_MPESA_CALLBACK

        return $tx;
    }

    private function createCompletedTransaction(): Transaction
    {
        $tx = $this->createAwaitingMpesaCallbackTransaction();

        // Create a mock M-Pesa callback
        $mpesaRequest = MpesaRequest::fromCallback(
            checkoutRequestId: 'ws_CO_COMPLETED',
            mpesaReceiptNumber: 'RKL_COMPLETED',
            phoneNumber: PhoneNumber::fromKenyan('254712345678'),
            amountKes: Money::kes(150000),
            callbackReceivedAt: new DateTimeImmutable()
        );

        $tx->recordMpesaCallback($mpesaRequest); // AWAITING_MPESA_CALLBACK → AWAITING_DERIV_CONFIRMATION

        // Use reflection to set status to COMPLETED (bypassing Deriv transfer for test)
        $ref = new \ReflectionClass($tx);
        $statusProp = $ref->getProperty('status');
        $statusProp->setAccessible(true);
        $statusProp->setValue($tx, TransactionStatus::COMPLETED);

        return $tx;
    }

    private function createFailedTransaction(): Transaction
    {
        $tx = $this->createAwaitingMpesaCallbackTransaction();
        
        // Fail the transaction
        $tx->fail('test_failure', 'Test failure reason');
        
        return $tx;
    }
}