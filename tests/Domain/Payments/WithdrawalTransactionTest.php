<?php
declare(strict_types=1);

namespace PenPay\Tests\Domain\Payments\Aggregate;

use PenPay\Domain\Payments\Aggregate\WithdrawalTransaction;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\Currency;
use PenPay\Domain\Payments\Entity\DerivWithdrawal;
use PenPay\Domain\Payments\Entity\MpesaDisbursement;
use PenPay\Domain\Payments\Event\TransactionCreated;
use PenPay\Domain\Payments\Event\TransactionCompleted;
use PenPay\Domain\Payments\Event\TransactionFailed;
use PHPUnit\Framework\TestCase;
use LogicException;

final class WithdrawalTransactionTest extends TestCase
{
    public function test_initiate_creates_pending_withdrawal_with_usd_amount(): void
    {
        $txId = TransactionId::generate();
        $usd = Money::usd(5000); // $50.00
        $idem = IdempotencyKey::fromHeader('idem-withdraw-001');

        $tx = WithdrawalTransaction::initiate(
            id: $txId,
            userId: 'user_123',  // Added userId
            amountUsd: $usd,
            idempotencyKey: $idem,
            lockedExchangeRate: 130.5
        );

        $this->assertSame($txId, $tx->id());
        $this->assertSame('user_123', $tx->userId());  // Added assertion
        $this->assertSame(TransactionType::WITHDRAWAL, $tx->type());
        $this->assertTrue($tx->status()->isPending());
        $this->assertSame(5000, $tx->amountUsd()->cents);
        $this->assertSame(130.5, $tx->exchangeRate());
        $this->assertSame($idem, $tx->idempotencyKey());

        $events = $tx->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(TransactionCreated::class, $events[0]);
    }

    public function test_record_deriv_debit_attaches_entity_but_keeps_pending(): void
    {
        $tx = $this->createPendingWithdrawal();

        $tx->recordDerivDebit(
            derivTransferId: 'TR123456',  
            derivTxnId: 'DW123456',       
            executedAt: new \DateTimeImmutable('2025-04-05 10:00:00'),
            rawResponse: ['success' => 1]
        );

        $deriv = $tx->derivWithdrawal();
        $this->assertNotNull($deriv);
        $this->assertSame('TR123456', $deriv->derivTransferId());  
        $this->assertSame('DW123456', $deriv->derivTxnId());       
        $this->assertTrue($tx->status()->isPending()); 
        $this->assertTrue($tx->hasDerivDebit());
    }

    public function test_record_mpesa_disbursement_marks_completed_and_emits_event(): void
    {
        $tx = $this->createPendingWithdrawal();
        
        // Clear the TransactionCreated event first
        $tx->releaseEvents();
        
        $tx->recordDerivDebit('TR999', 'DW999', new \DateTimeImmutable());

        $kes = Money::kes(650000); 

        $tx->recordMpesaDisbursement(
            mpesaReceipt: 'RBK123456789',
            resultCode: 0,
            executedAt: new \DateTimeImmutable(),
            amountKes: $kes
        );

        $this->assertTrue($tx->status()->isCompleted());
        $this->assertSame($kes, $tx->amountKes());
        $this->assertNotNull($tx->mpesaDisbursement());

        $events = $tx->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(TransactionCompleted::class, $events[0]);
    }

    public function test_fail_marks_failed_and_emits_event(): void
    {
        $tx = $this->createPendingWithdrawal();
        
        // Clear the TransactionCreated event first
        $tx->releaseEvents();

        $tx->fail('deriv_withdraw_failed', 'Insufficient balance');

        $this->assertTrue($tx->status()->isFailed());
        $this->assertTrue($tx->isFinalized());

        $events = $tx->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(TransactionFailed::class, $events[0]);
    }

    public function test_cannot_record_deriv_debit_twice_idempotent(): void
    {
        $tx = $this->createPendingWithdrawal();
        $tx->recordDerivDebit('TR1', 'DW1', new \DateTimeImmutable());  // Added derivTxnId

        // Second call — should not throw
        $tx->recordDerivDebit('TR2', 'DW2', new \DateTimeImmutable());  // Added derivTxnId
        $this->assertSame('TR1', $tx->derivWithdrawal()->derivTransferId());  // Updated method
        $this->assertSame('DW1', $tx->derivWithdrawal()->derivTxnId());       // Updated method
    }

    public function test_cannot_modify_finalized_transaction(): void
    {
        $tx = $this->createPendingWithdrawal();
        $tx->recordDerivDebit('TR1', 'DW1', new \DateTimeImmutable());  // Added derivTxnId
        $tx->recordMpesaDisbursement('RBK1', 0, new \DateTimeImmutable(), Money::kes(100000));

        $this->expectException(LogicException::class);
        $tx->recordDerivDebit('TR2', 'DW2', new \DateTimeImmutable());  // Added derivTxnId
    }

    public function test_initiate_rejects_non_usd_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Withdrawal amount must be in USD');

        WithdrawalTransaction::initiate(
            id: TransactionId::generate(),
            userId: 'user_456',
            amountUsd: Money::kes(500000),
            idempotencyKey: IdempotencyKey::generate()  // Use generate() instead of fromHeader()
        );
    }

    private function createPendingWithdrawal(): WithdrawalTransaction
    {
        return WithdrawalTransaction::initiate(
            id: TransactionId::generate(),
            userId: 'test_user_123',  // Added userId
            amountUsd: Money::usd(10000),
            idempotencyKey: IdempotencyKey::fromHeader('test-123')
        );
    }
}