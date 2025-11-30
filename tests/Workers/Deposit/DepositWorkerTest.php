<?php
declare(strict_types=1);

namespace Tests\Workers\Deposit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PenPay\Workers\Deposit\DepositWorker;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Infrastructure\Mpesa\Deposit\MpesaClientInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Shared\Kernel\TransactionId;
use Psr\Log\LoggerInterface;

final class DepositWorkerTest extends TestCase
{
    private TransactionRepositoryInterface&MockObject $txRepo;
    private MpesaClientInterface&MockObject $mpesa;
    private RedisStreamPublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;
    private DepositWorker $worker;

    protected function setUp(): void
    {
        $this->txRepo = $this->createMock(TransactionRepositoryInterface::class);
        $this->mpesa = $this->createMock(MpesaClientInterface::class);
        $this->publisher = $this->createMock(RedisStreamPublisherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->worker = new DepositWorker(
            $this->txRepo,
            $this->mpesa,
            $this->publisher,
            $this->logger,
            2 // max retries
        );
    }

    public function test_successful_stk_push(): void
    {
        $tx = Transaction::initiateDeposit(
            TransactionId::generate(),
            Money::kes(50000),
            IdempotencyKey::generate()
        );

        $this->txRepo->method('getById')->willReturn($tx);
        $this->txRepo->expects($this->once())->method('save');

        $response = new class {
            public string $CheckoutRequestID = 'ws_CO_TEST123';
        };

        $this->mpesa->expects($this->once())
            ->method('initiateStkPush')
            ->willReturn($response);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.mpesa_requested', $this->arrayHasKey('checkout_request_id'));

        $this->worker->handle([
            'transaction_id' => (string)$tx->getId(),
            'kes_cents' => 50000,
            'phone' => '254712345678'
        ]);
    }

    public function test_idempotency_skips_already_processed(): void
    {
        $tx = Transaction::initiateDeposit(
            TransactionId::generate(),
            Money::kes(50000),
            IdempotencyKey::generate()
        );
        // Simulate already having M-Pesa request
        $mpesaRequest = \PenPay\Domain\Payments\Entity\MpesaRequest::initiated(
            $tx->getId(),
            'ws_CO_ALREADY',
            '254712345678',
            50000
        );
        $tx->recordMpesaCallback($mpesaRequest);

        $this->txRepo->method('getById')->willReturn($tx);
        $this->txRepo->expects($this->never())->method('save');
        $this->mpesa->expects($this->never())->method('initiateStkPush');

        $this->worker->handle([
            'transaction_id' => (string)$tx->getId(),
            'kes_cents' => 50000
        ]);
    }

    public function test_failure_after_retries_marks_transaction_failed(): void
    {
        $tx = Transaction::initiateDeposit(
            TransactionId::generate(),
            Money::kes(50000),
            IdempotencyKey::generate()
        );

        $this->txRepo->method('getById')->willReturn($tx);
        $this->txRepo->expects($this->once())->method('save');

        $this->mpesa->expects($this->exactly(2))
            ->method('initiateStkPush')
            ->willThrowException(new \RuntimeException('Network error'));

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', $this->anything());

        $this->worker->handle([
            'transaction_id' => (string)$tx->getId(),
            'kes_cents' => 50000,
            'phone' => '254712345678'
        ]);
    }
}