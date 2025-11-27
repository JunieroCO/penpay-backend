<?php
declare(strict_types=1);

namespace PenPay\Tests\Workers;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use PenPay\Workers\DerivDebitWorker;
use PenPay\Domain\Payments\Aggregate\WithdrawalTransaction;
use PenPay\Domain\Payments\Repository\WithdrawalTransactionRepositoryInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Infrastructure\Deriv\DerivGatewayInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use PenPay\Domain\Payments\Entity\DerivTransferResult;

final class DerivDebitWorkerTest extends TestCase
{
    private WithdrawalTransactionRepositoryInterface&MockObject $repo;
    private DerivGatewayInterface&MockObject $gateway;
    private OneTimeSecretStoreInterface&MockObject $secretStore;
    private RedisStreamPublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->repo        = $this->createMock(WithdrawalTransactionRepositoryInterface::class);
        $this->gateway     = $this->createMock(DerivGatewayInterface::class);
        $this->secretStore = $this->createMock(OneTimeSecretStoreInterface::class);
        $this->publisher   = $this->createMock(RedisStreamPublisherInterface::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
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

        // Mock Deriv API response
        $result = DerivTransferResult::success(
            transferId: 'T123',
            txnId: 'D456',
            amountUsd: 100.00,
            rawResponse: ['ok' => true]
        );

        $this->gateway
            ->expects($this->once())
            ->method('paymentAgentWithdraw')
            ->with(
                'CR100001',
                100.00,
                'ABCDEFGH',
                $txId
            )
            ->willReturn($result);

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

        $worker = new DerivDebitWorker(
            $this->repo,
            $this->gateway,
            $this->secretStore,
            $this->publisher,
            $this->logger
        );

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'secret-key-1',
        ]);

        // FIX: Use derivWithdrawal() method instead of hasDerivDebit()
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

        // missing key => fail
        $this->publisher->expects($this->once())
            ->method('publish')
            ->with(
                'withdrawals.failed',
                $this->callback(fn ($p) => $p['transaction_id'] === $txId)
            );

        $worker = new DerivDebitWorker(
            $this->repo,
            $this->gateway,
            $this->secretStore,
            $this->publisher,
            $this->logger
        );

        $worker->handle([
            'transaction_id' => $txId,
            // no secret_key => failure
        ]);

        $this->assertTrue($tx->isFinalized());
        $this->assertEquals('FAILED', $tx->status()->value);
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

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.failed', $this->anything());

        $worker = new DerivDebitWorker(
            $this->repo,
            $this->gateway,
            $this->secretStore,
            $this->publisher,
            $this->logger
        );

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'expired-key',
        ]);

        $this->assertTrue($tx->isFinalized());
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

        // simulate Deriv failure
        $result = DerivTransferResult::failure(
            errorMessage: 'Insufficient balance',
            rawResponse: ['error' => 'Insufficient balance']
        );

        $this->gateway->expects($this->once())
            ->method('paymentAgentWithdraw')
            ->willReturn($result);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('withdrawals.failed', $this->anything());

        $worker = new DerivDebitWorker(
            $this->repo,
            $this->gateway,
            $this->secretStore,
            $this->publisher,
            $this->logger
        );

        $worker->handle([
            'transaction_id' => $txId,
            'secret_key'     => 'foo',
        ]);

        $this->assertEquals('FAILED', $tx->status()->value);
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

        // Use derivWithdrawal() instead of hasDerivDebit()
        $this->assertNotNull($tx->derivWithdrawal());
        $this->assertEquals('PENDING', $tx->status()->value);
    }
}