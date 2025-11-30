<?php
declare(strict_types=1);

namespace PenPay\Tests\Workers\Withdrawal;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use PenPay\Workers\Withdrawal\DerivDebitWorker;
use PenPay\Domain\Payments\Aggregate\WithdrawalTransaction;
use PenPay\Domain\Payments\Repository\WithdrawalTransactionRepositoryInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Infrastructure\Deriv\Withdrawal\DerivWithdrawalGatewayInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use PenPay\Domain\Payments\Entity\DerivWithdrawalResult;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
use React\Promise\Promise;

final class DerivDebitWorkerTest extends TestCase
{
    private WithdrawalTransactionRepositoryInterface&MockObject $repo;
    private DerivWithdrawalGatewayInterface&MockObject $gateway;
    private OneTimeSecretStoreInterface&MockObject $secretStore;
    private RedisStreamPublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;
    private LoopInterface&MockObject $loop;

    protected function setUp(): void
    {
        $this->repo        = $this->createMock(WithdrawalTransactionRepositoryInterface::class);
        $this->gateway     = $this->createMock(DerivWithdrawalGatewayInterface::class);
        $this->secretStore = $this->createMock(OneTimeSecretStoreInterface::class);
        $this->publisher   = $this->createMock(RedisStreamPublisherInterface::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->loop        = $this->createMock(LoopInterface::class);
    }

    private function createTransaction(): WithdrawalTransaction
    {
        $tx = WithdrawalTransaction::initiate(
            id: TransactionId::generate(),
            userId: 'U123',
            amountUsd: Money::usd(10000), // $100
            idempotencyKey: IdempotencyKey::generate()
        );

        // Attach payment agent credentials
        $tx->attachPaymentAgentCredentials('CR100001', 'secret-token');

        return $tx;
    }

    private function createWorker(): DerivDebitWorker
    {
        return new DerivDebitWorker(
            $this->repo,
            $this->gateway,
            $this->secretStore,
            $this->publisher,
            $this->logger,
            $this->loop
        );
    }

    /** @test */
    public function it_processes_successful_deriv_debit(): void
    {
        $tx = $this->createTransaction();
        $txId = (string) $tx->id();

        // Mock repository
        $this->repo->expects($this->once())
            ->method('getById')
            ->with($this->callback(fn($id) => (string)$id === $txId))
            ->willReturn($tx);

        // Mock secret store returning valid verification code
        $this->secretStore
            ->expects($this->once())
            ->method('getAndDelete')
            ->with('secret-key-1')
            ->willReturn('ABCDEFGH');

        // Mock Deriv API response with a resolved promise
        $result = DerivWithdrawalResult::success(
            transferId: 'T123',
            txnId: 'D456',
            amountUsd: 100.00,
            rawResponse: ['ok' => true]
        );

        $promise = new Promise(function ($resolve) use ($result) {
            $resolve($result);
        });

        $this->gateway
            ->expects($this->once())
            ->method('withdraw')
            ->with(
                'CR100001',
                100.00,
                'ABCDEFGH',
                $txId
            )
            ->willReturn($promise);

        // Mock event loop to execute promise callbacks immediately
        $this->loop->expects($this->once())
            ->method('run')
            ->willReturnCallback(function () use ($promise) {
                // Manually trigger promise resolution for testing
                $promise->then(fn($r) => $r);
            });

        // Repo save should be called after debit
        $this->repo
            ->expects($this->once())
            ->method('save')
            ->with($tx);

        // Event publish
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with(
                'withdrawals.deriv_debited',
                ['transaction_id' => $txId]
            );

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'secret-key-1',
        ]);

        $this->assertNotNull($tx->derivWithdrawal());
    }

    /** @test */
    public function it_fails_if_verification_code_missing(): void
    {
        $tx = $this->createTransaction();
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
                $this->callback(fn ($p) => $p['transaction_id'] === $txId)
            );

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            // no secret_key => failure
        ]);

        $this->assertTrue($tx->isFinalized());
        $this->assertSame(TransactionStatus::FAILED, $tx->status());
    }

    /** @test */
    public function it_fails_if_verification_code_expired(): void
    {
        $tx = $this->createTransaction();
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
            ->with('withdrawals.failed', $this->anything());

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'expired-key',
        ]);

        $this->assertTrue($tx->isFinalized());
        $this->assertSame(TransactionStatus::FAILED, $tx->status());
    }

    /** @test */
    public function it_handles_deriv_api_failure(): void
    {
        $tx = $this->createTransaction();
        $txId = (string) $tx->id();

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->secretStore->expects($this->once())
            ->method('getAndDelete')
            ->willReturn('ABCDEFGH');

        // simulate Deriv failure with a resolved promise containing failure result
        $result = DerivWithdrawalResult::failure(
            errorMessage: 'Insufficient balance',
            rawResponse: ['error' => 'Insufficient balance']
        );

        $promise = new Promise(function ($resolve) use ($result) {
            $resolve($result);
        });

        $this->gateway->expects($this->once())
            ->method('withdraw')
            ->willReturn($promise);

        $this->loop->expects($this->once())
            ->method('run')
            ->willReturnCallback(function () use ($promise) {
                $promise->then(fn($r) => $r);
            });

        $this->repo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.failed', $this->anything());

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'foo',
        ]);

        $this->assertSame(TransactionStatus::FAILED, $tx->status());
    }

    /** @test */
    public function it_handles_deriv_api_exception(): void
    {
        $tx = $this->createTransaction();
        $txId = (string) $tx->id();

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->secretStore->expects($this->once())
            ->method('getAndDelete')
            ->willReturn('ABCDEFGH');

        // simulate exception in promise
        $promise = new Promise(function ($resolve, $reject) {
            $reject(new \RuntimeException('Network timeout'));
        });

        $this->gateway->expects($this->once())
            ->method('withdraw')
            ->willReturn($promise);

        $this->loop->expects($this->once())
            ->method('run')
            ->willReturnCallback(function () use ($promise) {
                $promise->then(null, fn($e) => $e);
            });

        $this->repo->expects($this->once())
            ->method('save')
            ->with($tx);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.failed', $this->callback(function ($data) {
                return $data['reason'] === 'deriv_withdraw_exception'
                    && str_contains($data['detail'], 'Network timeout');
            }));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('DerivDebitWorker: exception', $this->anything());

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'foo',
        ]);

        $this->assertSame(TransactionStatus::FAILED, $tx->status());
    }

    /** @test */
    public function it_skips_already_finalized_transactions(): void
    {
        $tx = $this->createTransaction();
        $tx->fail('already_failed', 'Previous failure');
        $txId = (string) $tx->id();

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        // Should not call gateway or publisher
        $this->gateway->expects($this->never())->method('withdraw');
        $this->publisher->expects($this->never())->method('publish');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('DerivDebitWorker: already finalized, skipping', ['transaction_id' => $txId]);

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'foo',
        ]);

        $this->assertTrue($tx->isFinalized());
    }

    /** @test */
    public function it_fails_when_payment_agent_login_missing(): void
    {
        $tx = WithdrawalTransaction::initiate(
            id: TransactionId::generate(),
            userId: 'U123',
            amountUsd: Money::usd(10000),
            idempotencyKey: IdempotencyKey::generate()
        );
        // Don't attach payment agent credentials
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
                return $data['reason'] === 'missing_deriv_payment_agent_credentials';
            }));

        $worker = $this->createWorker();

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'foo',
        ]);

        $this->assertSame(TransactionStatus::FAILED, $tx->status());
    }

    /** @test */
    public function it_handles_missing_transaction_id_in_payload(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('DerivDebitWorker: missing transaction_id', []);

        // No other methods should be called
        $this->repo->expects($this->never())->method('getById');

        $worker = $this->createWorker();
        $worker->handle([]);
    }

    /** @test */
    public function it_handles_invalid_transaction_id_format(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
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
    public function record_deriv_debit_attaches_entity_but_keeps_pending(): void
    {
        $tx = $this->createTransaction();
        
        $tx->recordDerivDebit(
            derivTransferId: 'T123',
            derivTxnId: 'D456',
            executedAt: new \DateTimeImmutable(),
            rawResponse: ['success' => true]
        );

        $this->assertNotNull($tx->derivWithdrawal());
        $this->assertSame(TransactionStatus::PENDING, $tx->status());
    }

    /** @test */
    public function it_logs_withdrawal_initiation_details(): void
    {
        $tx = $this->createTransaction();
        $txId = (string) $tx->id();

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $this->secretStore->expects($this->once())
            ->method('getAndDelete')
            ->willReturn('VERIFY123');

        $result = DerivWithdrawalResult::success('T1', 'D1', 100.00, []);
        $promise = new Promise(function ($resolve) use ($result) {
            $resolve($result);
        });

        $this->gateway->expects($this->once())
            ->method('withdraw')
            ->willReturn($promise);

        $this->loop->expects($this->once())
            ->method('run')
            ->willReturnCallback(function () use ($promise) {
                $promise->then(fn($r) => $r);
            });

        // Verify the info log contains all the necessary details
        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message, $context) use ($txId) {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    $this->assertSame('DerivDebitWorker: calling paymentagent_withdraw', $message);
                    $this->assertSame($txId, $context['transaction_id']);
                    $this->assertSame(100.0, $context['amount_usd']);
                    $this->assertSame('CR100001', $context['pa_login']);
                } elseif ($callCount === 2) {
                    $this->assertSame('DerivDebitWorker: success', $message);
                    $this->assertSame($txId, $context['transaction_id']);
                }
            });

        $this->repo->expects($this->once())->method('save');
        $this->publisher->expects($this->once())->method('publish');

        $worker = $this->createWorker();
        $worker->handle([
            'transaction_id' => $txId,
            'secret_key' => 'test-key',
        ]);
    }
}