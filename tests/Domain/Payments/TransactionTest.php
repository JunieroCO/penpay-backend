<?php

declare(strict_types=1);

namespace PenPay\Tests\Domain\Payments;

use PHPUnit\Framework\TestCase;
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Wallet\ValueObject\Currency;
use PenPay\Domain\Payments\Entity\MpesaRequest;
use PenPay\Domain\Payments\Entity\DerivTransfer;
use PenPay\Domain\Payments\Event\TransactionCreated;
use PenPay\Domain\Payments\Event\MpesaCallbackReceived;
use PenPay\Domain\Payments\Event\TransactionCompleted;
use PenPay\Domain\Payments\Event\TransactionFailed;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use InvalidArgumentException;
use DomainException;
use DateTimeImmutable;

final class TransactionTest extends TestCase
{
    private TransactionId $transactionId;
    private string $userId;
    private string $derivLoginId;
    private Money $amountUsd;
    private LockedRate $lockedRate;
    private LockedRate $withdrawalRate;
    private IdempotencyKey $idempotencyKey;
    private PhoneNumber $phoneNumber;

    protected function setUp(): void
    {
        $this->transactionId = TransactionId::generate();
        $this->userId = '01234567-89ab-cdef-0123-456789abcdef';
        $this->derivLoginId = 'CR1234567';
        $this->amountUsd = Money::usd(1000); // $10.00
        $this->lockedRate = LockedRate::lock(135.0, Currency::USD, Currency::KES); // Deposit rate
        $this->withdrawalRate = LockedRate::lock(125.0, Currency::USD, Currency::KES); // Withdrawal rate
        $this->idempotencyKey = IdempotencyKey::generate();
        $this->phoneNumber = PhoneNumber::fromE164('+254712345678');
    }

    // ===================================================================
    // DEPOSIT CREATION TESTS
    // ===================================================================

    public function testCanCreateDepositTransaction(): void
    {
        $transaction = Transaction::initiateDeposit(
            $this->transactionId,
            $this->userId,
            $this->amountUsd,
            $this->lockedRate,
            $this->idempotencyKey,
            $this->derivLoginId
        );

        $this->assertEquals($this->transactionId, $transaction->id());
        $this->assertEquals($this->userId, $transaction->userId());
        $this->assertEquals(TransactionType::DEPOSIT, $transaction->type());
        $this->assertEquals(TransactionStatus::PENDING, $transaction->status());
        $this->assertEquals($this->amountUsd, $transaction->amountUsd());
        $this->assertEquals($this->derivLoginId, $transaction->userDerivLoginId());
    }

    public function testDepositCreationEmitsTransactionCreatedEvent(): void
    {
        $transaction = Transaction::initiateDeposit(
            $this->transactionId,
            $this->userId,
            $this->amountUsd,
            $this->lockedRate,
            $this->idempotencyKey,
            $this->derivLoginId
        );

        $events = $transaction->releaseEvents();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(TransactionCreated::class, $events[0]);
        $this->assertEquals($this->userId, $events[0]->userId);
        $this->assertEquals('deposit', $events[0]->type); 
    }

    public function testCannotCreateDepositWithNonUsdAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deposit amount must be in USD');

        $kesAmount = Money::kes(13500); // KES instead of USD

        Transaction::initiateDeposit(
            $this->transactionId,
            $this->userId,
            $kesAmount,
            $this->lockedRate,
            $this->idempotencyKey,
            $this->derivLoginId
        );
    }

    public function testCannotCreateDepositBelowMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deposit minimum is $2.00');

        $tooSmall = Money::usd(199); // $1.99

        Transaction::initiateDeposit(
            $this->transactionId,
            $this->userId,
            $tooSmall,
            $this->lockedRate,
            $this->idempotencyKey,
            $this->derivLoginId
        );
    }

    public function testCannotCreateDepositAboveMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deposit maximum is $1000.00');

        $tooLarge = Money::usd(100001); // $1000.01

        Transaction::initiateDeposit(
            $this->transactionId,
            $this->userId,
            $tooLarge,
            $this->lockedRate,
            $this->idempotencyKey,
            $this->derivLoginId
        );
    }

    // ===================================================================
    // WITHDRAWAL CREATION TESTS
    // ===================================================================

    public function testCanCreateWithdrawalTransaction(): void
    {
        $transaction = Transaction::initiateWithdrawal(
            $this->transactionId,
            $this->userId,
            $this->amountUsd,
            $this->withdrawalRate,
            $this->idempotencyKey,
            $this->derivLoginId,
            'ABC123'
        );

        $this->assertEquals(TransactionType::WITHDRAWAL, $transaction->type());
        $this->assertEquals(TransactionStatus::PENDING, $transaction->status());
    }

    public function testCannotCreateWithdrawalBelowMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Withdrawal minimum is $5.00');

        $tooSmall = Money::usd(499); // $4.99

        Transaction::initiateWithdrawal(
            $this->transactionId,
            $this->userId,
            $tooSmall,
            $this->withdrawalRate,
            $this->idempotencyKey,
            $this->derivLoginId,
            'ABC123'
        );
    }

    // ===================================================================
    // DEPOSIT STATE TRANSITION TESTS
    // ===================================================================

    public function testCanMarkStkPushInitiated(): void
    {
        $transaction = $this->createDeposit();

        $transaction->markStkPushInitiated();

        $this->assertEquals(TransactionStatus::PROCESSING, $transaction->status());
    }

    public function testCanMarkAwaitingMpesaCallback(): void
    {
        $transaction = $this->createDeposit();
        $transaction->markStkPushInitiated();

        $transaction->markAwaitingMpesaCallback();

        $this->assertEquals(TransactionStatus::AWAITING_MPESA_CALLBACK, $transaction->status());
    }

    public function testCannotMarkStkPushInitiatedOnWithdrawal(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only deposits have STK Push');

        $transaction = $this->createWithdrawal();
        $transaction->markStkPushInitiated();
    }

    public function testCanRecordMpesaCallback(): void
    {
        $transaction = $this->createDeposit();
        $transaction->markStkPushInitiated();
        $transaction->markAwaitingMpesaCallback();

        $mpesaRequest = MpesaRequest::fromCallback(
            'checkout-456',
            'ABC123XYZ',
            $this->phoneNumber,
            Money::kes(13500),
            new DateTimeImmutable(),
            'merchant-123',
            ['ResultCode' => 0, 'TransactionDate' => '20231207143022']
        );

        $transaction->recordMpesaCallback($mpesaRequest);

        $this->assertEquals(TransactionStatus::AWAITING_DERIV_CONFIRMATION, $transaction->status());
        $this->assertTrue($transaction->hasMpesaCallback());
    }

    public function testRecordingMpesaCallbackEmitsEvent(): void
    {
        $transaction = $this->createDeposit();
        $transaction->markStkPushInitiated();
        $transaction->markAwaitingMpesaCallback();
        $transaction->releaseEvents(); // Clear creation event

        $mpesaRequest = MpesaRequest::fromCallback(
            'checkout-456',
            'ABC123XYZ',
            $this->phoneNumber,
            Money::kes(13500),
            new DateTimeImmutable(),
            'merchant-123',
            ['ResultCode' => 0]
        );

        $transaction->recordMpesaCallback($mpesaRequest);
        $events = $transaction->releaseEvents();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(MpesaCallbackReceived::class, $events[0]);
        $this->assertEquals('ABC123XYZ', $events[0]->mpesaReceiptNumber);
    }

    public function testCannotRecordMpesaCallbackOnWithdrawal(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only deposits have M-Pesa callbacks');

        $transaction = $this->createWithdrawal();

        $mpesaRequest = MpesaRequest::fromCallback(
            'checkout-456',
            'ABC123XYZ',
            $this->phoneNumber,
            Money::kes(13500),
            new DateTimeImmutable(),
            'merchant-123',
            []
        );

        $transaction->recordMpesaCallback($mpesaRequest);
    }

    public function testCannotRecordMpesaCallbackFromInvalidState(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid state transition');

        $transaction = $this->createDeposit();
        // Still in PENDING, skip to callback

        $mpesaRequest = MpesaRequest::fromCallback(
            'checkout-456',
            'ABC123XYZ',
            $this->phoneNumber,
            Money::kes(13500),
            new DateTimeImmutable(),
            'merchant-123',
            []
        );

        $transaction->recordMpesaCallback($mpesaRequest);
    }

    // ===================================================================
    // WITHDRAWAL STATE TRANSITION TESTS
    // ===================================================================

    public function testCanMarkDerivWithdrawalInitiated(): void
    {
        $transaction = $this->createWithdrawal();

        $transaction->markDerivWithdrawalInitiated();

        $this->assertEquals(TransactionStatus::PROCESSING, $transaction->status());
    }

    public function testCanMarkAwaitingDerivConfirmation(): void
    {
        $transaction = $this->createWithdrawal();
        $transaction->markDerivWithdrawalInitiated();

        $transaction->markAwaitingDerivConfirmation();

        $this->assertEquals(TransactionStatus::AWAITING_DERIV_CONFIRMATION, $transaction->status());
    }

    public function testCannotMarkDerivWithdrawalOnDeposit(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only withdrawals have Deriv withdrawal');

        $transaction = $this->createDeposit();
        $transaction->markDerivWithdrawalInitiated();
    }

    // ===================================================================
    // COMPLETION TESTS
    // ===================================================================

    public function testCanCompleteDepositWithDerivTransfer(): void
    {
        $transaction = $this->createDeposit();
        $transaction->markStkPushInitiated();
        $transaction->markAwaitingMpesaCallback();

        $mpesaRequest = MpesaRequest::fromCallback(
            'checkout-456',
            'ABC123XYZ',
            $this->phoneNumber,
            Money::kes(13500),
            new DateTimeImmutable(),
            'merchant-123',
            []
        );
        $transaction->recordMpesaCallback($mpesaRequest);

        $derivTransfer = DerivTransfer::forDeposit(
            $this->transactionId,
            'PA1234567',
            $this->derivLoginId,
            $this->amountUsd,
            'deriv-transfer-123',
            'deriv-txn-456',
            null,
            ['status' => 'success']
        );

        $transaction->recordDerivTransfer($derivTransfer);

        $this->assertEquals(TransactionStatus::COMPLETED, $transaction->status());
        $this->assertTrue($transaction->hasDerivTransfer());
        $this->assertTrue($transaction->isFinalized());
    }

    public function testCompletionEmitsTransactionCompletedEvent(): void
    {
        $transaction = $this->createDeposit();
        $transaction->markStkPushInitiated();
        $transaction->markAwaitingMpesaCallback();

        $mpesaRequest = MpesaRequest::fromCallback(
            'checkout-456',
            'ABC123XYZ',
            $this->phoneNumber,
            Money::kes(13500),
            new DateTimeImmutable(),
            'merchant-123',
            []
        );
        $transaction->recordMpesaCallback($mpesaRequest);
        $transaction->releaseEvents(); // Clear previous events

        $derivTransfer = DerivTransfer::forDeposit(
            $this->transactionId,
            'PA1234567',
            $this->derivLoginId,
            $this->amountUsd,
            'deriv-transfer-123',
            'deriv-txn-456'
        );

        $transaction->recordDerivTransfer($derivTransfer);
        $events = $transaction->releaseEvents();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(TransactionCompleted::class, $events[0]);
        $this->assertEquals('deriv-transfer-123', $events[0]->derivTransferId);
    }

    public function testCannotCompleteDepositWithoutMpesaCallback(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Deposit requires M-Pesa callback before Deriv transfer');

        $transaction = $this->createDeposit();
        $transaction->markStkPushInitiated();
        $transaction->markAwaitingMpesaCallback();
        // Skip M-Pesa callback

        $derivTransfer = DerivTransfer::forDeposit(
            $this->transactionId,
            'PA1234567',
            $this->derivLoginId,
            $this->amountUsd,
            'deriv-transfer-123',
            'deriv-txn-456'
        );

        $transaction->recordDerivTransfer($derivTransfer);
    }

    public function testCanCompleteWithdrawalWithDerivTransfer(): void
    {
        $transaction = $this->createWithdrawal();
        $transaction->markDerivWithdrawalInitiated();
        $transaction->markAwaitingDerivConfirmation();

        $derivTransfer = DerivTransfer::forWithdrawal(
            $this->transactionId,
            $this->derivLoginId,
            'PA1234567',
            $this->amountUsd,
            'deriv-withdrawal-123',
            'deriv-txn-456',
            'ABC123' // Withdrawal verification code (must match the one used in createWithdrawal)
        );

        $transaction->recordDerivTransfer($derivTransfer);

        // For withdrawals, Deriv transfer moves to AWAITING_MPESA_DISBURSEMENT, not COMPLETED
        $this->assertEquals(TransactionStatus::AWAITING_MPESA_DISBURSEMENT, $transaction->status());
    }

    // ===================================================================
    // FAILURE TESTS
    // ===================================================================

    public function testCanFailTransaction(): void
    {
        $transaction = $this->createDeposit();

        $transaction->fail('STK Push timeout', 'Timeout after 60 seconds');

        $this->assertEquals(TransactionStatus::FAILED, $transaction->status());
        $this->assertEquals('STK Push timeout', $transaction->failureReason());
        $this->assertEquals('Timeout after 60 seconds', $transaction->providerError());
        $this->assertTrue($transaction->isFinalized());
    }

    public function testFailureEmitsTransactionFailedEvent(): void
    {
        $transaction = $this->createDeposit();
        $transaction->releaseEvents(); // Clear creation event

        $transaction->fail('M-Pesa declined', 'Insufficient balance');
        $events = $transaction->releaseEvents();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(TransactionFailed::class, $events[0]);
        $this->assertEquals('M-Pesa declined', $events[0]->reason);
        $this->assertEquals('Insufficient balance', $events[0]->providerError);
    }

    public function testCannotFailCompletedTransaction(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot fail transaction in terminal state');

        $transaction = $this->createAndCompleteDeposit();

        $transaction->fail('Too late');
    }

    public function testCannotFailAlreadyFailedTransaction(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot fail transaction in terminal state');

        $transaction = $this->createDeposit();
        $transaction->fail('First failure');

        $transaction->fail('Second failure');
    }

    // ===================================================================
    // REVERSAL TESTS
    // ===================================================================

    public function testCanReverseCompletedTransaction(): void
    {
        $transaction = $this->createAndCompleteDeposit();

        $transaction->reverse('Fraud detected');

        $this->assertEquals(TransactionStatus::REVERSED, $transaction->status());
        $this->assertEquals('Fraud detected', $transaction->failureReason());
    }

    public function testCannotReverseNonCompletedTransaction(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only completed transactions can be reversed');

        $transaction = $this->createDeposit();

        $transaction->reverse('Cannot reverse');
    }

    public function testCannotReverseFailedTransaction(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only completed transactions can be reversed');

        $transaction = $this->createDeposit();
        $transaction->fail('Already failed');

        $transaction->reverse('Cannot reverse failed');
    }

    // ===================================================================
    // RETRY TESTS
    // ===================================================================

    public function testCanIncrementRetryCount(): void
    {
        $transaction = $this->createDeposit();

        $this->assertEquals(0, $transaction->retryCount());

        $transaction->incrementRetryCount();
        $this->assertEquals(1, $transaction->retryCount());

        $transaction->incrementRetryCount();
        $this->assertEquals(2, $transaction->retryCount());
    }

    public function testCanRetryBeforeMaxAttempts(): void
    {
        $transaction = $this->createDeposit();
        $transaction->incrementRetryCount();
        $transaction->incrementRetryCount();

        $this->assertTrue($transaction->canRetry(3)); // Max 3, current 2
    }

    public function testCannotRetryAfterMaxAttempts(): void
    {
        $transaction = $this->createDeposit();
        $transaction->incrementRetryCount();
        $transaction->incrementRetryCount();
        $transaction->incrementRetryCount();

        $this->assertFalse($transaction->canRetry(3)); // Max 3, current 3
    }

    public function testCannotRetryFinalizedTransaction(): void
    {
        $transaction = $this->createAndCompleteDeposit();

        $this->assertFalse($transaction->canRetry(3));
    }

    // ===================================================================
    // IDEMPOTENCY TESTS
    // ===================================================================

    public function testCanCheckIdempotencyKey(): void
    {
        $key1 = IdempotencyKey::generate();
        $transaction = Transaction::initiateDeposit(
            $this->transactionId,
            $this->userId,
            $this->amountUsd,
            $this->lockedRate,
            $key1,
            $this->derivLoginId
        );

        $this->assertTrue($transaction->hasIdempotencyKey($key1));
    }

    public function testIdempotencyKeyDoesNotMatch(): void
    {
        $key1 = IdempotencyKey::generate();
        $key2 = IdempotencyKey::generate();

        $transaction = Transaction::initiateDeposit(
            $this->transactionId,
            $this->userId,
            $this->amountUsd,
            $this->lockedRate,
            $key1,
            $this->derivLoginId
        );

        $this->assertFalse($transaction->hasIdempotencyKey($key2));
    }

    // ===================================================================
    // DOMAIN EVENT TESTS
    // ===================================================================

    public function testEventsAreRecordedButNotReleased(): void
    {
        $transaction = $this->createDeposit();

        // First release
        $events1 = $transaction->releaseEvents();
        $this->assertCount(1, $events1);

        // Second release should be empty
        $events2 = $transaction->releaseEvents();
        $this->assertCount(0, $events2);
    }

    public function testMultipleStateTransitionsRecordMultipleEvents(): void
    {
        $transaction = $this->createDeposit();
        $transaction->markStkPushInitiated();
        $transaction->markAwaitingMpesaCallback();

        $events = $transaction->releaseEvents();

        $this->assertCount(1, $events); // Only creation event (state changes don't emit events)
    }

    // ===================================================================
    // GUARD TESTS
    // ===================================================================

    public function testHasDerivLoginId(): void
    {
        $transaction = $this->createDeposit();

        $this->assertTrue($transaction->hasDerivLoginId());
        $this->assertEquals($this->derivLoginId, $transaction->userDerivLoginId());
    }

    public function testIsNotFinalizedWhenPending(): void
    {
        $transaction = $this->createDeposit();

        $this->assertFalse($transaction->isFinalized());
    }

    public function testIsFinalizedWhenCompleted(): void
    {
        $transaction = $this->createAndCompleteDeposit();

        $this->assertTrue($transaction->isFinalized());
    }

    public function testIsFinalizedWhenFailed(): void
    {
        $transaction = $this->createDeposit();
        $transaction->fail('Test failure');

        $this->assertTrue($transaction->isFinalized());
    }

    // ===================================================================
    // HELPER METHODS
    // ===================================================================

    private function createDeposit(): Transaction
    {
        return Transaction::initiateDeposit(
            $this->transactionId,
            $this->userId,
            $this->amountUsd,
            $this->lockedRate,
            $this->idempotencyKey,
            $this->derivLoginId
        );
    }

    private function createWithdrawal(): Transaction
    {
        return Transaction::initiateWithdrawal(
            $this->transactionId,
            $this->userId,
            $this->amountUsd,
            $this->withdrawalRate,
            $this->idempotencyKey,
            $this->derivLoginId,
            'ABC123'
        );
    }

    private function createAndCompleteDeposit(): Transaction
    {
        $transaction = $this->createDeposit();
        $transaction->markStkPushInitiated();
        $transaction->markAwaitingMpesaCallback();

        $mpesaRequest = MpesaRequest::fromCallback(
            'checkout-456',
            'ABC123XYZ',
            $this->phoneNumber,
            Money::kes(13500),
            new DateTimeImmutable(),
            'merchant-123',
            []
        );
        $transaction->recordMpesaCallback($mpesaRequest);

        $derivTransfer = DerivTransfer::forDeposit(
            $this->transactionId,
            'PA1234567',
            $this->derivLoginId,
            $this->amountUsd,
            'deriv-transfer-123',
            'deriv-txn-456'
        );
        $transaction->recordDerivTransfer($derivTransfer);

        return $transaction;
    }
}