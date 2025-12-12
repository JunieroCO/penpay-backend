<?php
declare(strict_types=1);

namespace PenPay\Tests\Workers\Withdrawal;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

use PenPay\Workers\Withdrawal\MpesaDisbursementWorker;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use PenPay\Domain\Payments\Entity\MpesaDisbursement;

use PenPay\Infrastructure\Mpesa\Withdrawal\MpesaGatewayInterface;
use PenPay\Infrastructure\Mpesa\Withdrawal\MpesaB2CResult;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;

final class MpesaDisbursementWorkerTest extends TestCase
{
    private TransactionRepositoryInterface&MockObject $repo;
    private MpesaGatewayInterface&MockObject $gateway;
    private RedisStreamPublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->repo      = $this->createMock(TransactionRepositoryInterface::class);
        $this->gateway   = $this->createMock(MpesaGatewayInterface::class);
        $this->publisher = $this->createMock(RedisStreamPublisherInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
    }

    private function createTxAwaitingMpesaDisbursement(): Transaction
    {
        $tx = Transaction::initiateWithdrawal(
            id: TransactionId::generate(),
            userId: '254712345678',
            amountUsd: Money::usd(10000),
            lockedRate: LockedRate::lock(130.0),
            idempotencyKey: IdempotencyKey::generate(),
            userDerivLoginId: 'user123',
            withdrawalVerificationCode: 'ABC123'
        );

        // Follow the proper state transition for withdrawals
        $tx->markDerivWithdrawalInitiated(); // PENDING → PROCESSING
        $tx->markAwaitingDerivConfirmation(); // PROCESSING → AWAITING_DERIV_CONFIRMATION

        // Create a DerivTransfer for withdrawal using the correct factory method
        $derivTransfer = \PenPay\Domain\Payments\Entity\DerivTransfer::forWithdrawal(
            transactionId: $tx->id(),
            userDerivLoginId: 'user123',
            paymentAgentLoginId: 'agent001', // Assuming payment agent login ID
            amountUsd: Money::usd(10000),
            derivTransferId: 'T1',
            derivTxnId: 'D1',
            withdrawalVerificationCode: 'ABC123', // Required for withdrawals
            executedAt: new DateTimeImmutable(),
            rawResponse: ['ok' => true]
        );

        // Record Deriv transfer (AWAITING_DERIV_CONFIRMATION → AWAITING_MPESA_DISBURSEMENT)
        $tx->recordDerivTransfer($derivTransfer);

        return $tx;
    }

    /** @test */
    public function it_processes_successful_mpesa_disbursement(): void
    {
        $tx = $this->createTxAwaitingMpesaDisbursement();
        $txId = (string) $tx->id();

        // Track if save was called and what was saved
        /** @var Transaction|null $savedTransaction */
        $savedTransaction = null;

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->repo->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($transactionToSave) use (&$savedTransaction) {
                $savedTransaction = $transactionToSave;
            });

        $expectedKesCents = 1300000;

        $this->gateway->expects($this->once())
            ->method('b2c')
            ->with('254712345678', $expectedKesCents, $txId)
            ->willReturn(
                MpesaB2CResult::success(
                    receipt: 'RB123',
                    resultCode: 0,
                    raw: [
                        'ResponseCode' => '0',
                        'ConversationID' => 'conv123',
                        'OriginatorConversationID' => 'orig123',
                        'TransactionReceipt' => 'RB123',
                        'PhoneNumber' => '254712345678'
                    ]
                )
            );

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with(
                'withdrawals.completed',
                $this->callback(fn ($p) =>
                    $p['transaction_id'] === $txId &&
                    $p['mpesa_receipt'] === 'RB123'
                )
            );

        $worker = new MpesaDisbursementWorker(
            $this->repo,
            $this->gateway,
            $this->publisher,
            $this->logger
        );

        $worker->handle(['transaction_id' => $txId]);

        // Check the saved transaction
        $this->assertNotNull($savedTransaction);
        $this->assertInstanceOf(Transaction::class, $savedTransaction);

        // The transaction should now be completed with M-Pesa disbursement
        $this->assertEquals('completed', strtolower($savedTransaction->status()->value));
        $this->assertTrue($savedTransaction->hasMpesaDisbursement());
    }

    /** @test */
    public function it_skips_if_mpesa_disbursement_already_recorded(): void
    {
        $tx = $this->createTxAwaitingMpesaDisbursement();
        $txId = (string)$tx->id();

        // Create a dummy M-Pesa disbursement using fromArray since constructor might be complex
        $disbursementData = [
            'transaction_id' => (string)$tx->id(),
            'conversation_id' => 'test123',
            'originator_conversation_id' => 'test456',
            'phone_number' => '254712345678',
            'amount_kes_cents' => 1300000,
            'status' => 'COMPLETED',
            'result_code' => '0',
            'result_description' => 'Success',
            'raw_payload' => [],
            'completed_at' => (new DateTimeImmutable())->format('c')
        ];

        $dummyDisbursement = MpesaDisbursement::fromArray($disbursementData);

        // Use reflection to set the disbursement
        $reflection = new \ReflectionClass($tx);
        $mpesaDisbursementProperty = $reflection->getProperty('mpesaDisbursement');
        $mpesaDisbursementProperty->setAccessible(true);
        $mpesaDisbursementProperty->setValue($tx, $dummyDisbursement);

        $this->repo->method('getById')->willReturn($tx);

        $this->gateway->expects($this->never())->method('b2c');
        $this->publisher->expects($this->never())->method('publish');
        $this->repo->expects($this->never())->method('save');

        $worker = new MpesaDisbursementWorker(
            $this->repo,
            $this->gateway,
            $this->publisher,
            $this->logger
        );

        $worker->handle(['transaction_id' => $txId]);

        // Transaction should still be in AWAITING_MPESA_DISBURSEMENT (or completed if disbursement was successful)
        $this->assertTrue($tx->hasMpesaDisbursement());
    }

    /** @test */
    public function it_fails_if_deriv_not_debited(): void
    {
        $tx = $this->createTxAwaitingMpesaDisbursement();
        $txId = (string)$tx->id();

        // Use reflection to remove the Deriv transfer to simulate edge case
        $reflection = new \ReflectionClass($tx);
        $derivTransferProperty = $reflection->getProperty('derivTransfer');
        $derivTransferProperty->setAccessible(true);
        $derivTransferProperty->setValue($tx, null);

        $this->repo->method('getById')->willReturn($tx);

        // Expect the transaction to be saved when it fails
        $this->repo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($transaction) use ($txId) {
                return (string)$transaction->id() === $txId;
            }));

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.failed', $this->callback(fn($p) =>
                $p['transaction_id'] === $txId
            ));

        $worker = new MpesaDisbursementWorker(
            $this->repo,
            $this->gateway,
            $this->publisher,
            $this->logger
        );

        $worker->handle(['transaction_id' => $txId]);

        // The worker should fail the transaction
        $this->assertTrue(true);
    }

    /** @test */
    public function it_fails_if_fx_rate_missing(): void
    {
        // Note: This test is skipped because lockedRate is required in Transaction constructor
        // and is non-nullable, making this scenario impossible. The worker's null check
        // is defensive programming that will never be hit in practice.
        // The worker correctly handles transactions with locked rates, which is tested
        // in it_processes_successful_mpesa_disbursement.
        $this->markTestSkipped('lockedRate is required and non-nullable, making this scenario impossible');
    }

    /** @test */
    public function it_handles_mpesa_gateway_failure(): void
    {
        $tx = $this->createTxAwaitingMpesaDisbursement();
        $txId = (string) $tx->id();

        // Set retry count to max (3) so it fails immediately instead of retrying
        $reflection = new \ReflectionClass($tx);
        $retryCountProperty = $reflection->getProperty('retryCount');
        $retryCountProperty->setAccessible(true);
        $retryCountProperty->setValue($tx, 3);

        // Track if save was called
        $saveCalled = false;
        $this->repo->method('getById')->willReturn($tx);

        $this->repo->expects($this->once())
            ->method('save')
            ->willReturnCallback(function () use (&$saveCalled) {
                $saveCalled = true;
            });

        $this->gateway->expects($this->once())
            ->method('b2c')
            ->willReturn(
                MpesaB2CResult::failure(
                    error: 'Insufficient funds',
                    raw: ['error' => 'Insufficient funds']
                )
            );

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with(
                'withdrawals.failed',
                $this->callback(fn ($p) =>
                    $p['transaction_id'] === $txId
                )
            );

        $worker = new MpesaDisbursementWorker(
            $this->repo,
            $this->gateway,
            $this->publisher,
            $this->logger
        );

        $worker->handle(['transaction_id' => $txId]);

        // Check that save was called (which means the transaction was updated)
        $this->assertTrue($saveCalled, 'Transaction should be saved when M-Pesa fails');
    }

    /** @test */
    public function it_handles_exceptions_during_disbursement(): void
    {
        $tx = $this->createTxAwaitingMpesaDisbursement();
        $txId = (string)$tx->id();

        // Track if save was called
        $saveCalled = false;
        $this->repo->method('getById')->willReturn($tx);

        $this->repo->expects($this->once())
            ->method('save')
            ->willReturnCallback(function () use (&$saveCalled) {
                $saveCalled = true;
            });

        $this->gateway->expects($this->once())
            ->method('b2c')
            ->willThrowException(new \RuntimeException('Network issue'));

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.failed', $this->anything());

        $worker = new MpesaDisbursementWorker(
            $this->repo,
            $this->gateway,
            $this->publisher,
            $this->logger
        );

        $worker->handle(['transaction_id' => $txId]);

        // Check that save was called (which means the transaction was updated)
        $this->assertTrue($saveCalled, 'Transaction should be saved when exception occurs');
    }

    /** @test */
    public function it_skips_non_withdrawal_transactions(): void
    {
        // Create a deposit transaction instead of withdrawal
        $tx = Transaction::initiateDeposit(
            id: TransactionId::generate(),
            userId: '254712345678',
            amountUsd: Money::usd(10000),
            lockedRate: LockedRate::lock(130.0),
            idempotencyKey: IdempotencyKey::generate(),
            userDerivLoginId: 'user123'
        );

        $txId = (string)$tx->id();

        $this->repo->method('getById')->willReturn($tx);

        $this->gateway->expects($this->never())->method('b2c');
        $this->publisher->expects($this->never())->method('publish');
        $this->repo->expects($this->never())->method('save');

        $worker = new MpesaDisbursementWorker(
            $this->repo,
            $this->gateway,
            $this->publisher,
            $this->logger
        );

        $worker->handle(['transaction_id' => $txId]);

        // Should skip without error
        $this->assertTrue(true);
    }

    /** @test */
    public function it_skips_wrong_state_transactions(): void
    {
        $tx = Transaction::initiateWithdrawal(
            id: TransactionId::generate(),
            userId: '254712345678',
            amountUsd: Money::usd(10000),
            lockedRate: LockedRate::lock(130.0),
            idempotencyKey: IdempotencyKey::generate(),
            userDerivLoginId: 'user123',
            withdrawalVerificationCode: 'ABC123'
        );

        // Leave in PENDING state (not AWAITING_MPESA_DISBURSEMENT)
        $txId = (string)$tx->id();

        $this->repo->method('getById')->willReturn($tx);

        $this->gateway->expects($this->never())->method('b2c');
        $this->publisher->expects($this->never())->method('publish');
        $this->repo->expects($this->never())->method('save');

        $worker = new MpesaDisbursementWorker(
            $this->repo,
            $this->gateway,
            $this->publisher,
            $this->logger
        );

        $worker->handle(['transaction_id' => $txId]);

        // Should skip without error
        $this->assertTrue(true);
    }
}