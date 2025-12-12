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
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Wallet\ValueObject\Currency;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
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
        $this->txRepo     = $this->createMock(TransactionRepositoryInterface::class);
        $this->mpesa      = $this->createMock(MpesaClientInterface::class);
        $this->publisher  = $this->createMock(RedisStreamPublisherInterface::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->worker = new DepositWorker(
            $this->txRepo,
            $this->mpesa,
            $this->publisher,
            $this->logger,
            2 // max retries
        );
    }

    /** @test */
    public function successful_stk_push(): void
    {
        $txId       = TransactionId::generate();
        $userId     = UserId::generate();
        $lockedRate = LockedRate::lock(130.0, Currency::USD, Currency::KES);

        $transaction = Transaction::initiateDeposit(
            id:               $txId,
            userId:           (string) $userId,
            amountUsd:        Money::usd(5000), // $50.00
            lockedRate:       $lockedRate,
            idempotencyKey:   IdempotencyKey::generate(),
            userDerivLoginId: 'CR1234567'
        );

        $this->txRepo->method('getById')
            ->with($txId)
            ->willReturn($transaction);

        $this->txRepo->expects($this->once())->method('save');

        $response = new class {
            public string $CheckoutRequestID = 'ws_CO_TEST123';
            public ?string $MerchantRequestID = null;
            public string $ResponseCode = '0';
            public string $ResponseDescription = 'Success';
        };

        // Expect 254712345678 (without +)
        $this->mpesa->expects($this->once())
            ->method('initiateStkPush')
            ->with(
                '254712345678',
                650000,
                (string) $txId,
                $this->anything()
            )
            ->willReturn($response);

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.mpesa_requested', $this->arrayHasKey('checkout_request_id'));

        $this->logger->expects($this->atLeastOnce())->method('info');

        // Use the correct format that works: 254712345678
        $this->worker->handle([
            'transaction_id' => (string) $txId,
            'amount_kes_cents' => 650000,
            'phone' => '254712345678'
        ]);

        // Check that transaction has M-Pesa request
        $this->assertNotNull($transaction->mpesaRequest());
        // Check transaction is in correct state
        $this->assertTrue($transaction->status()->isAwaitingDerivConfirmation());
    }

    /** @test */
    public function idempotency_skips_already_processed(): void
    {
        $txId       = TransactionId::generate();
        $userId     = UserId::generate();
        $lockedRate = LockedRate::lock(130.0, Currency::USD, Currency::KES);

        $transaction = Transaction::initiateDeposit(
            id:               $txId,
            userId:           (string) $userId,
            amountUsd:        Money::usd(5000),
            lockedRate:       $lockedRate,
            idempotencyKey:   IdempotencyKey::generate(),
            userDerivLoginId: 'CR1234567'
        );

        // First, transition to PROCESSING state
        $transaction->markStkPushInitiated();
        $transaction->markAwaitingMpesaCallback();

        // Then simulate STK push already done
        $mpesaRequest = \PenPay\Domain\Payments\Entity\MpesaRequest::initiated(
            transactionId:     $txId,
            checkoutRequestId: 'ws_CO_ALREADY',
            phoneNumber:       PhoneNumber::fromE164('+254712345678'),
            amountKes:         Money::kes(650000)
        );

        $transaction->recordMpesaCallback($mpesaRequest);

        $this->txRepo->method('getById')->with($txId)->willReturn($transaction);

        $this->txRepo->expects($this->never())->method('save');
        $this->mpesa->expects($this->never())->method('initiateStkPush');
        $this->publisher->expects($this->never())->method('publish');
        $this->logger->expects($this->atLeastOnce())->method('info');

        // Use the correct format that works: 254712345678
        $this->worker->handle([
            'transaction_id' => (string) $txId,
            'amount_kes_cents' => 650000,
            'phone' => '254712345678'
        ]);

        // Transaction should still have M-Pesa request
        $this->assertNotNull($transaction->mpesaRequest());
    }

    /** @test */
    public function failure_after_retries_marks_transaction_failed(): void
    {
        $txId       = TransactionId::generate();
        $userId     = UserId::generate();
        $lockedRate = LockedRate::lock(130.0, Currency::USD, Currency::KES);

        $transaction = Transaction::initiateDeposit(
            id:               $txId,
            userId:           (string) $userId,
            amountUsd:        Money::usd(5000),
            lockedRate:       $lockedRate,
            idempotencyKey:   IdempotencyKey::generate(),
            userDerivLoginId: 'CR1234567'
        );

        $this->txRepo->method('getById')->with($txId)->willReturn($transaction);
        $this->txRepo->expects($this->once())->method('save');

        // Expect 254712345678 (without +)
        $this->mpesa->expects($this->exactly(2))
            ->method('initiateStkPush')
            ->with(
                '254712345678',
                650000,
                (string) $txId,
                $this->anything()
            )
            ->willThrowException(new \RuntimeException('Network timeout'));

        $this->publisher->expects($this->once())
            ->method('publish')
            ->with('deposits.failed', $this->callback(function ($payload) {
                return $payload['reason'] === 'mpesa_stk_push_failed'
                    && $payload['provider_error'] === 'Network timeout';
            }));

        $this->logger->expects($this->atLeastOnce())->method('warning');
        $this->logger->expects($this->atLeastOnce())->method('error');

        // Use the correct format that works: 254712345678
        $this->worker->handle([
            'transaction_id' => (string) $txId,
            'amount_kes_cents' => 650000,
            'phone' => '254712345678'
        ]);

        // Add assertions
        $this->assertTrue($transaction->isFinalized());
        $this->assertTrue($transaction->status()->isFailed());
        $this->assertNotNull($transaction->failureReason());
    }
}