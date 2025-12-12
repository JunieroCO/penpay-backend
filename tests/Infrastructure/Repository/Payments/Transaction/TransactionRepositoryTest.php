<?php
declare(strict_types=1);

namespace Tests\Infrastructure\Repository\Payments\Transaction;

use PHPUnit\Framework\TestCase;
use PDO;
use PenPay\Infrastructure\Repository\Payments\Transaction\{
    TransactionWriteRepository,
    TransactionReadRepository,
    TransactionRowMapper,
    TransactionSerializer,
    IdempotencyKeyHasher
};
use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Entity\{DerivTransfer, MpesaRequest, MpesaDisbursement};
use PenPay\Domain\Payments\ValueObject\{TransactionType, IdempotencyKey};
use PenPay\Domain\Wallet\ValueObject\{LockedRate, Money, Currency};
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;

final class TransactionRepositoryTest extends TestCase
{
    private PDO $pdo;
    private TransactionWriteRepository $write;
    private TransactionReadRepository $read;

    protected function setUp(): void
    {
        // Connect to MySQL test database
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'] ?? '127.0.0.1',
            $_ENV['DB_PORT'] ?? '3307',
            $_ENV['DB_NAME'] ?? 'penpay_test'
        );

        $this->pdo = new PDO(
            $dsn,
            $_ENV['DB_USER'] ?? 'penpay',
            $_ENV['DB_PASS'] ?? 'secret',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        // Ensure migration columns exist
        $this->ensureMigrationColumns();

        // Clean up tables before each test
        $this->cleanupTables();

        $serializer = new TransactionSerializer();
        $hasher = new IdempotencyKeyHasher();
        $mapper = new TransactionRowMapper();

        $this->write = new TransactionWriteRepository(
            pdo: $this->pdo,
            serializer: $serializer,
            hasher: $hasher,
            eventPublisher: null
        );

        $this->read = new TransactionReadRepository(
            pdo: $this->pdo,
            mapper: $mapper
        );
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        $this->cleanupTables();
    }

    private function ensureMigrationColumns(): void
    {
        // Check and add user_deriv_login_id column if it doesn't exist
        $check = $this->pdo->query("SHOW COLUMNS FROM transactions LIKE 'user_deriv_login_id'")->fetch();
        if ($check === false) {
            $this->pdo->exec("ALTER TABLE transactions ADD COLUMN user_deriv_login_id VARCHAR(50) NULL COMMENT 'User Deriv account login ID'");
        }
        
        // Check and add withdrawal_verification_code column if it doesn't exist
        $check = $this->pdo->query("SHOW COLUMNS FROM transactions LIKE 'withdrawal_verification_code'")->fetch();
        if ($check === false) {
            $this->pdo->exec("ALTER TABLE transactions ADD COLUMN withdrawal_verification_code VARCHAR(6) NULL COMMENT '6-character verification code for withdrawals'");
        }
        
        // Check and add index if it doesn't exist
        $indexes = $this->pdo->query("SHOW INDEXES FROM transactions WHERE Key_name = 'idx_user_deriv_login_id'")->fetchAll();
        if (empty($indexes)) {
            $this->pdo->exec("ALTER TABLE transactions ADD INDEX idx_user_deriv_login_id (user_deriv_login_id)");
        }
        
        // Check and add mpesa_receipt_number to mpesa_disbursements if it doesn't exist
        $check = $this->pdo->query("SHOW COLUMNS FROM mpesa_disbursements LIKE 'mpesa_receipt_number'")->fetch();
        if ($check === false) {
            $this->pdo->exec("ALTER TABLE mpesa_disbursements ADD COLUMN mpesa_receipt_number VARCHAR(50) NULL COMMENT 'M-Pesa receipt number from B2C response'");
        }
    }

    private function cleanupTables(): void
    {
        // Order matters due to foreign key constraints
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('TRUNCATE TABLE mpesa_disbursements');
        $this->pdo->exec('TRUNCATE TABLE deriv_withdrawals');
        $this->pdo->exec('TRUNCATE TABLE deriv_transfers');
        $this->pdo->exec('TRUNCATE TABLE mpesa_requests');
        $this->pdo->exec('TRUNCATE TABLE transactions');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ========================================================================
    // DEPOSIT FLOW TESTS
    // ========================================================================

    public function test_deposit_transaction_persists_correctly(): void
    {
        // Arrange
        $id = TransactionId::generate();
        $userId = 'user-123';
        $amountUsd = Money::usd(5000); // $50.00
        $rate = $this->createLockedRate(155.00);
        $idempotency = $this->createIdempotencyKey('deposit-idem-123');

        // Act
        $tx = Transaction::initiateDeposit(
            id: $id,
            userId: $userId,
            amountUsd: $amountUsd,
            lockedRate: $rate,
            idempotencyKey: $idempotency,
            userDerivLoginId: 'CR12345'
        );

        $this->write->save($tx);

        // Assert
        $loaded = $this->read->findById($id);

        $this->assertNotNull($loaded, 'Transaction should be persisted');
        $this->assertSame('deposit', $loaded->type()->value); // FIXED: lowercase
        $this->assertSame($userId, $loaded->userId());
        $this->assertSame(5000, $loaded->amountUsd()->cents);
        $this->assertSame(775000, $loaded->amountKes()->cents); // 50 * 155 = 7750 KES
        $this->assertSame('CR12345', $loaded->userDerivLoginId());
    }

    public function test_deposit_full_flow_from_pending_to_completed(): void
    {
        // Arrange
        $id = TransactionId::generate();
        $userId = 'user-deposit-flow';
        $amountUsd = Money::usd(1000); // $10.00
        $amountKes = Money::kes(150000); // KES 1,500

        $tx = Transaction::initiateDeposit(
            id: $id,
            userId: $userId,
            amountUsd: $amountUsd,
            lockedRate: $this->createLockedRate(150.00),
            idempotencyKey: $this->createIdempotencyKey('deposit-flow-key'),
            userDerivLoginId: 'CR11111'
        );

        // Act & Assert - Step 1: Initial save (PENDING)
        $this->write->save($tx);
        $loaded = $this->read->findById($id);
        $this->assertSame('PENDING', $loaded->status()->value);

        // Act & Assert - Step 2: STK Push initiated (PROCESSING)
        $tx->markStkPushInitiated();
        $this->write->save($tx);
        $loaded = $this->read->findById($id);
        $this->assertSame('PROCESSING', $loaded->status()->value);

        // Act & Assert - Step 3: Awaiting M-Pesa callback
        $tx->markAwaitingMpesaCallback();
        $this->write->save($tx);
        $loaded = $this->read->findById($id);
        $this->assertSame('AWAITING_MPESA_CALLBACK', $loaded->status()->value);

        // Act & Assert - Step 4: M-Pesa callback received
        $mpesaRequest = new MpesaRequest(
            transactionId: $id,
            phoneNumber: PhoneNumber::fromE164('+254712345678'),
            amountKes: $amountKes,
            merchantRequestId: 'MERCHANT-REQ-001',
            checkoutRequestId: 'CHECKOUT-REQ-001',
            mpesaReceiptNumber: 'RCP123456',
            callbackReceivedAt: new \DateTimeImmutable(),
            initiatedAt: new \DateTimeImmutable()
        );

        $tx->recordMpesaCallback($mpesaRequest);
        $this->write->save($tx);
        
        $loaded = $this->read->findById($id);
        $this->assertSame('AWAITING_DERIV_CONFIRMATION', $loaded->status()->value);
        $this->assertNotNull($loaded->mpesaRequest());
        $this->assertSame('RCP123456', $loaded->mpesaRequest()->mpesaReceiptNumber);

        // Act & Assert - Step 5: Deriv transfer completes (COMPLETED for deposits)
        $derivTransfer = DerivTransfer::forDeposit(
            transactionId: $id,
            paymentAgentLoginId: 'PA001',
            userDerivLoginId: 'CR11111',
            amountUsd: $amountUsd,
            derivTransferId: 'DT-DEPOSIT-123',
            derivTxnId: 'TXN-DEPOSIT-123',
            executedAt: new \DateTimeImmutable(),
            rawResponse: ['status' => 'success']
        );

        $tx->recordDerivTransfer($derivTransfer);
        $this->write->save($tx);

        // Final assertions
        $final = $this->read->findById($id);
        $this->assertTrue($final->status()->isCompleted(), 'Deposit should be COMPLETED after Deriv transfer');
        $this->assertNotNull($final->derivTransfer());
        $this->assertSame('DT-DEPOSIT-123', $final->derivTransfer()->derivTransferId);
        $this->assertTrue($final->derivTransfer()->isDeposit());
    }

    // ========================================================================
    // WITHDRAWAL FLOW TESTS
    // ========================================================================

    public function test_withdrawal_transaction_persists_correctly(): void
    {
        // Arrange
        $id = TransactionId::generate();
        $userId = 'user-456';
        $amountUsd = Money::usd(2000); // $20.00
        $rate = $this->createLockedRate(150.00);
        $idempotency = $this->createIdempotencyKey('withdraw-idem-456');

        // Act
        $tx = Transaction::initiateWithdrawal(
            id: $id,
            userId: $userId,
            amountUsd: $amountUsd,
            lockedRate: $rate,
            idempotencyKey: $idempotency,
            userDerivLoginId: 'CR99999',
            withdrawalVerificationCode: 'ABC123'
        );

        $this->write->save($tx);

        // Assert
        $loaded = $this->read->findById($id);

        $this->assertNotNull($loaded, 'Transaction should be persisted');
        $this->assertSame('withdrawal', $loaded->type()->value); // FIXED: lowercase
        $this->assertSame($userId, $loaded->userId());
        $this->assertSame(2000, $loaded->amountUsd()->cents);
        $this->assertSame(300000, $loaded->amountKes()->cents); // 20 * 150 = 3000 KES
        $this->assertSame('ABC123', $loaded->withdrawalVerificationCode());
    }

    public function test_withdrawal_full_flow_from_pending_to_completed(): void
    {
        // Arrange
        $id = TransactionId::generate();
        $userId = 'user-withdrawal-flow';
        $amountUsd = Money::usd(1333); // $13.33
        $amountKes = Money::kes(200000); // KES 2,000
        $verificationCode = 'XYZ789';

        $tx = Transaction::initiateWithdrawal(
            id: $id,
            userId: $userId,
            amountUsd: $amountUsd,
            lockedRate: $this->createLockedRate(150.00),
            idempotencyKey: $this->createIdempotencyKey('withdrawal-flow-key'),
            userDerivLoginId: 'CR55555',
            withdrawalVerificationCode: $verificationCode
        );

        // Act & Assert - Step 1: Initial save (PENDING)
        $this->write->save($tx);
        $loaded = $this->read->findById($id);
        $this->assertSame('PENDING', $loaded->status()->value);
        $this->assertSame('withdrawal', $loaded->type()->value); // FIXED: lowercase

        // Act & Assert - Step 2: Deriv withdrawal initiated (PROCESSING)
        $tx->markDerivWithdrawalInitiated();
        $this->write->save($tx);
        $loaded = $this->read->findById($id);
        $this->assertSame('PROCESSING', $loaded->status()->value);

        // Act & Assert - Step 3: Awaiting Deriv confirmation
        $tx->markAwaitingDerivConfirmation();
        $this->write->save($tx);
        $loaded = $this->read->findById($id);
        $this->assertSame('AWAITING_DERIV_CONFIRMATION', $loaded->status()->value);

        // Act & Assert - Step 4: Deriv transfer/withdrawal completed
        $derivTransfer = DerivTransfer::forWithdrawal(
            transactionId: $id,
            userDerivLoginId: 'CR55555',
            paymentAgentLoginId: 'PA001',
            amountUsd: $amountUsd,
            derivTransferId: 'DT-WITHDRAW-456',
            derivTxnId: 'TXN-WITHDRAW-456',
            withdrawalVerificationCode: $verificationCode,
            executedAt: new \DateTimeImmutable(),
            rawResponse: ['status' => 'success']
        );

        $tx->recordDerivTransfer($derivTransfer);
        $this->write->save($tx);

        $loaded = $this->read->findById($id);
        $this->assertSame(
            'AWAITING_MPESA_DISBURSEMENT', 
            $loaded->status()->value,
            'After Deriv debit, withdrawal should await M-Pesa disbursement'
        );
        $this->assertNotNull($loaded->derivTransfer());
        $this->assertSame('DT-WITHDRAW-456', $loaded->derivTransfer()->derivTransferId);
        $this->assertTrue($loaded->derivTransfer()->isWithdrawal());

        // Act & Assert - Step 5: M-Pesa B2C disbursement completed (COMPLETED)
        $mpesaDisbursement = new MpesaDisbursement(
            transactionId: $id,
            phoneNumber: PhoneNumber::fromE164('+254700123456'),
            amountKes: $amountKes,
            conversationId: 'CONV-B2C-123',
            originatorConversationId: 'ORIG-B2C-123',
            mpesaReceiptNumber: 'B2C-RCP-789',
            resultCode: '0',
            resultDescription: 'The service request is processed successfully.',
            callbackReceivedAt: new \DateTimeImmutable(),
            initiatedAt: new \DateTimeImmutable(),
            rawPayload: ['ResultCode' => 0]
        );

        $tx->recordMpesaDisbursement($mpesaDisbursement);
        $this->write->save($tx);

        // Final assertions
        $final = $this->read->findById($id);
        $this->assertTrue(
            $final->status()->isCompleted(),
            'Withdrawal should be COMPLETED after M-Pesa disbursement'
        );
        $this->assertNotNull($final->mpesaDisbursement());
        $this->assertSame('CONV-B2C-123', $final->mpesaDisbursement()->conversationId);
        $this->assertSame('B2C-RCP-789', $final->mpesaDisbursement()->mpesaReceiptNumber);
        $this->assertTrue($final->mpesaDisbursement()->isSuccessful());
    }

    // ========================================================================
    // EDGE CASES & VALIDATION TESTS
    // ========================================================================

    public function test_deposit_below_minimum_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Deposit minimum is $2.00');

        Transaction::initiateDeposit(
            id: TransactionId::generate(),
            userId: 'user-123',
            amountUsd: Money::usd(199), // $1.99 - below minimum
            lockedRate: $this->createLockedRate(150.00),
            idempotencyKey: $this->createIdempotencyKey('test-key'),
            userDerivLoginId: 'CR12345'
        );
    }

    public function test_withdrawal_below_minimum_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Withdrawal minimum is $5.00');

        Transaction::initiateWithdrawal(
            id: TransactionId::generate(),
            userId: 'user-123',
            amountUsd: Money::usd(499), // $4.99 - below minimum
            lockedRate: $this->createLockedRate(150.00),
            idempotencyKey: $this->createIdempotencyKey('test-key'),
            userDerivLoginId: 'CR12345',
            withdrawalVerificationCode: 'ABC123'
        );
    }

    public function test_idempotency_key_prevents_duplicate_saves(): void
    {
        $id1 = TransactionId::generate();
        $id2 = TransactionId::generate();
        $idempotencyKey = $this->createIdempotencyKey('same-key-123');

        $tx1 = Transaction::initiateDeposit(
            id: $id1,
            userId: 'user-123',
            amountUsd: Money::usd(5000),
            lockedRate: $this->createLockedRate(150.00),
            idempotencyKey: $idempotencyKey,
            userDerivLoginId: 'CR12345'
        );

        $tx2 = Transaction::initiateDeposit(
            id: $id2,
            userId: 'user-123',
            amountUsd: Money::usd(5000),
            lockedRate: $this->createLockedRate(150.00),
            idempotencyKey: $idempotencyKey, // Same key
            userDerivLoginId: 'CR12345'
        );

        $this->write->save($tx1);

        // Second save should be prevented by unique constraint on idempotency_key_hash
        try {
            $this->write->save($tx2);
            $this->fail('Expected PDOException was not thrown');
        } catch (\PDOException $e) {
            // Check if it's a duplicate key error (SQLSTATE 23000)
            $this->assertSame(23000, $e->getCode());
        }
    }

    public function test_idempotency_key_prevents_duplicate_saves_with_detailed_error(): void
    {
        $id1 = TransactionId::generate();
        $id2 = TransactionId::generate();
        $idempotencyKey = $this->createIdempotencyKey('same-key-' . uniqid());

        $tx1 = Transaction::initiateDeposit(
            id: $id1,
            userId: 'user-123',
            amountUsd: Money::usd(5000),
            lockedRate: $this->createLockedRate(150.00),
            idempotencyKey: $idempotencyKey,
            userDerivLoginId: 'CR12345'
        );

        $tx2 = Transaction::initiateDeposit(
            id: $id2,
            userId: 'user-123',
            amountUsd: Money::usd(5000),
            lockedRate: $this->createLockedRate(150.00),
            idempotencyKey: $idempotencyKey, // Same key
            userDerivLoginId: 'CR12345'
        );

        // Save first transaction
        $this->write->save($tx1);
        
        // Try to save second transaction with same idempotency key
        $this->expectException(\PDOException::class);
        
        try {
            $this->write->save($tx2);
        } catch (\PDOException $e) {
            // Log the error for debugging
            error_log("PDOException caught: " . $e->getMessage());
            error_log("Error code: " . $e->getCode());
            throw $e;
        }
    }

    public function test_transaction_not_found_returns_null(): void
    {
        $nonExistentId = TransactionId::generate();
        $result = $this->read->findById($nonExistentId);
        
        $this->assertNull($result);
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    private function createLockedRate(float $rate): LockedRate
    {
        return new LockedRate(
            rate: $rate,
            from: Currency::USD,
            to: Currency::KES,
            lockedAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+15 minutes')
        );
    }

    private function createIdempotencyKey(string $value): IdempotencyKey
    {
        return new IdempotencyKey(
            value: $value,
            expiresAt: new \DateTimeImmutable('+1 hour')
        );
    }
}