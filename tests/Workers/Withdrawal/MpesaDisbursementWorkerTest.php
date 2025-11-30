<?php
declare(strict_types=1);

namespace PenPay\Tests\Workers\Withdrawal;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

use PenPay\Workers\Withdrawal\MpesaDisbursementWorker;
use PenPay\Domain\Payments\Aggregate\WithdrawalTransaction;
use PenPay\Domain\Payments\Repository\WithdrawalTransactionRepositoryInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;

use PenPay\Infrastructure\Mpesa\Withdrawal\MpesaGatewayInterface;
use PenPay\Infrastructure\Mpesa\Withdrawal\MpesaB2CResult;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;

final class MpesaDisbursementWorkerTest extends TestCase
{
    private WithdrawalTransactionRepositoryInterface&MockObject $repo;
    private MpesaGatewayInterface&MockObject $gateway;
    private RedisStreamPublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->repo      = $this->createMock(WithdrawalTransactionRepositoryInterface::class);
        $this->gateway   = $this->createMock(MpesaGatewayInterface::class);
        $this->publisher = $this->createMock(RedisStreamPublisherInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
    }

    private function createTxWithDerivDebit(): WithdrawalTransaction
    {
        $tx = WithdrawalTransaction::initiate(
            id: TransactionId::generate(),
            userId: '254712345678',
            amountUsd: Money::usd(10000),
            idempotencyKey: IdempotencyKey::generate(),
            lockedExchangeRate: 130.0
        );

        $tx->recordDerivDebit(
            'T123',
            'D456',
            new DateTimeImmutable(),
            ['ok' => true]
        );

        return $tx;
    }

    /** @test */
    public function it_processes_successful_mpesa_disbursement(): void
    {
        $tx = $this->createTxWithDerivDebit();
        $txId = (string) $tx->id();

        $this->repo->expects($this->once())
            ->method('getById')
            ->willReturn($tx);

        $expectedKesCents = 1300000;

        $this->gateway->expects($this->once())
            ->method('b2c')
            ->with('254712345678', $expectedKesCents, $txId)
            ->willReturn(
                MpesaB2CResult::success(
                    receipt: 'RB123',
                    resultCode: 0,
                    raw: ['ok' => true]
                )
            );

        $this->repo->expects($this->once())->method('save');

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

        $this->assertEquals('completed', strtolower($tx->status()->value));
        $this->assertNotNull($tx->mpesaDisbursement());
    }

    /** @test */
    public function it_skips_if_finalized(): void
    {
        $tx = $this->createTxWithDerivDebit();
        $tx->fail('test', 'done');

        $txId = (string)$tx->id();

        $this->repo->method('getById')->willReturn($tx);

        $this->gateway->expects($this->never())->method('b2c');
        $this->publisher->expects($this->never())->method('publish');

        $worker = new MpesaDisbursementWorker(
            $this->repo,
            $this->gateway,
            $this->publisher,
            $this->logger
        );

        $worker->handle(['transaction_id' => $txId]);

        $this->assertEquals('failed', strtolower($tx->status()->value));
    }

    /** @test */
    public function it_fails_if_deriv_not_debited(): void
    {
        $tx = WithdrawalTransaction::initiate(
            TransactionId::generate(),
            '254712345678',
            Money::usd(10000),
            IdempotencyKey::generate(),
            130.0
        );

        $txId = (string)$tx->id();

        $this->repo->method('getById')->willReturn($tx);

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

        $this->assertEquals('failed', strtolower($tx->status()->value));
    }

    /** @test */
    public function it_fails_if_fx_rate_missing(): void
    {
        $tx = WithdrawalTransaction::initiate(
            TransactionId::generate(),
            '254712345678',
            Money::usd(10000),
            IdempotencyKey::generate()
        );

        $tx->recordDerivDebit('T1', 'D1', new DateTimeImmutable());

        $txId = (string)$tx->id();

        $this->repo->method('getById')->willReturn($tx);

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

        $this->assertEquals('failed', strtolower($tx->status()->value));
    }

    /** @test */
    public function it_handles_mpesa_gateway_failure(): void
    {
        $tx = $this->createTxWithDerivDebit();
        $txId = (string) $tx->id();

        $this->repo->method('getById')->willReturn($tx);

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
            ->with('withdrawals.failed', $this->anything());

        $worker = new MpesaDisbursementWorker(
            $this->repo,
            $this->gateway,
            $this->publisher,
            $this->logger
        );

        $worker->handle(['transaction_id' => $txId]);

        $this->assertEquals('failed', strtolower($tx->status()->value));
    }

    /** @test */
    public function it_handles_exceptions_during_disbursement(): void
    {
        $tx = $this->createTxWithDerivDebit();
        $txId = (string)$tx->id();

        $this->repo->method('getById')->willReturn($tx);

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

        $this->assertEquals('failed', strtolower($tx->status()->value));
        $this->assertTrue($tx->isFinalized());
    }
}