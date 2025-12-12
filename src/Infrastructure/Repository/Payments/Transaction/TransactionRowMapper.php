<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\Payments\Transaction;

use DateTimeImmutable;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PenPay\Domain\Payments\ValueObject\TransactionStatus;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\Currency;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Payments\Entity\MpesaRequest;
use PenPay\Domain\Payments\Entity\DerivTransfer;
use PenPay\Domain\Payments\Entity\MpesaDisbursement;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Payments\Aggregate\Transaction as TxAggregate;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use InvalidArgumentException;

final class TransactionRowMapper
{
    private const DEFAULT_MPESA_MERCHANT_ID = 'merchant-unknown';
    private const DEFAULT_MPESA_CHECKOUT_ID = 'checkout-unknown';
    

    public function fromRow(array $row): TxAggregate
    {
        // Validate required fields
        $this->validateRequiredFields($row);
        
        $id = TransactionId::fromString($row['id']);
        $userId = (string)$row['user_id'];
        
        // Handle case-insensitive enum values
        $type = $this->mapTransactionType($row['type']);
        $status = TransactionStatus::from($row['state']);

        $amountUsd = Money::usd((int)$row['amount_usd_cents']);
        $amountKes = Money::kes((int)$row['amount_kes_cents']);

        $lockedRate = $this->mapLockedRate($row);
        $idempotencyKey = $this->mapIdempotencyKey($row);
        
        // Map optional entities
        $mpesaRequest = $this->mapMpesaRequest($id, $row);
        $derivTransfer = $this->mapDerivTransfer($id, $row);
        $mpesaDisbursement = $this->mapMpesaDisbursement($id, $row);
        
        // Get optional fields
        $userDerivLoginId = $row['user_deriv_login_id'] ?? null;
        $withdrawalVerificationCode = $row['withdrawal_verification_code'] ?? null;
        $failureReason = $row['failure_reason'] ?? null;
        $providerError = $row['provider_error'] ?? null;
        $retryCount = (int)($row['retry_count'] ?? 0);
        
        // Create transaction using reconstitute with all parameters
        return TxAggregate::reconstitute(
            id: $id,
            userId: $userId,
            type: $type,
            status: $status,
            amountUsd: $amountUsd,
            amountKes: $amountKes,
            lockedRate: $lockedRate,
            idempotencyKey: $idempotencyKey,
            mpesaRequest: $mpesaRequest,
            derivTransfer: $derivTransfer,
            mpesaDisbursement: $mpesaDisbursement,
            userDerivLoginId: $userDerivLoginId,
            withdrawalVerificationCode: $withdrawalVerificationCode,
            failureReason: $failureReason,
            providerError: $providerError,
            retryCount: $retryCount
        );
    }
    
    private function validateRequiredFields(array $row): void
    {
        $required = ['id', 'user_id', 'type', 'state', 'amount_usd_cents', 'amount_kes_cents', 'fx_rate', 'fx_rate_timestamp'];
        
        foreach ($required as $field) {
            if (!isset($row[$field]) || $row[$field] === '') {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }
    }
    
    private function mapTransactionType(string $type): TransactionType
    {
        // Normalize to lowercase for enum lookup
        $normalized = strtolower(trim($type));
        
        try {
            return TransactionType::from($normalized);
        } catch (\ValueError $e) {
            // Fallback for database values that might be uppercase
            if ($normalized === 'deposit') {
                return TransactionType::DEPOSIT;
            }
            if ($normalized === 'withdrawal') {
                return TransactionType::WITHDRAWAL;
            }
            throw new InvalidArgumentException("Invalid transaction type: {$type}");
        }
    }
    
    private function mapLockedRate(array $row): LockedRate
    {
        $lockedAt = new DateTimeImmutable($row['fx_rate_timestamp']);
        
        // Use actual expiration if stored, otherwise calculate
        if (isset($row['fx_rate_expires_at']) && $row['fx_rate_expires_at']) {
            $expiresAt = new DateTimeImmutable($row['fx_rate_expires_at']);
        } else {
            // Default 15-minute expiration
            $expiresAt = $lockedAt->modify('+15 minutes');
        }
        
        return new LockedRate(
            rate: (float)$row['fx_rate'],
            from: Currency::USD,
            to: Currency::KES,
            lockedAt: $lockedAt,
            expiresAt: $expiresAt
        );
    }
    
    private function mapIdempotencyKey(array $row): IdempotencyKey
    {
        $hash = $row['idempotency_key_hash'] ?? null;
        
        if (!$hash) {
            // Handle legacy records or create placeholder
            $hash = hash('sha256', $row['id'] . '_' . ($row['created_at'] ?? ''));
        }
        
        // Use actual expiration if stored, otherwise use created_at + 24h
        if (isset($row['idempotency_key_expires_at']) && $row['idempotency_key_expires_at']) {
            $expiresAt = new DateTimeImmutable($row['idempotency_key_expires_at']);
        } else {
            $createdAt = isset($row['created_at']) 
                ? new DateTimeImmutable($row['created_at'])
                : new DateTimeImmutable();
            $expiresAt = $createdAt->modify('+24 hours');
        }
        
        return new IdempotencyKey($hash, $expiresAt);
    }
    
    private function mapMpesaRequest(TransactionId $transactionId, array $row): ?MpesaRequest
    {
        // Check multiple possible indicators of MpesaRequest existence
        if (empty($row['mpesa_receipt_number']) && 
            empty($row['mpesa_merchant_request_id']) &&
            empty($row['mpesa_checkout_request_id'])) {
            return null;
        }
        
        // Validate required MpesaRequest fields
        if (empty($row['mpesa_phone_number']) || empty($row['mpesa_amount_kes_cents'])) {
            throw new InvalidArgumentException(
                "MpesaRequest requires phone number and amount"
            );
        }
        
        $callbackReceivedAt = null;
        if (!empty($row['mpesa_transaction_date'])) {
            try {
                $callbackReceivedAt = new DateTimeImmutable($row['mpesa_transaction_date']);
            } catch (\Exception $e) {
                // Log error but don't fail the entire mapping
                error_log("Invalid mpesa_transaction_date: " . $e->getMessage());
            }
        }
        
        $initiatedAt = null;
        if (!empty($row['mpesa_initiated_at'])) {
            try {
                $initiatedAt = new DateTimeImmutable($row['mpesa_initiated_at']);
            } catch (\Exception $e) {
                error_log("Invalid mpesa_initiated_at: " . $e->getMessage());
            }
        } elseif (!empty($row['created_at'])) {
            $initiatedAt = new DateTimeImmutable($row['created_at']);
        } else {
            $initiatedAt = new DateTimeImmutable();
        }
        
        return new MpesaRequest(
            transactionId: $transactionId,
            phoneNumber: PhoneNumber::fromE164($row['mpesa_phone_number']),
            amountKes: Money::kes((int)$row['mpesa_amount_kes_cents']),
            merchantRequestId: $row['mpesa_merchant_request_id'] ?? self::DEFAULT_MPESA_MERCHANT_ID,
            checkoutRequestId: $row['mpesa_checkout_request_id'] ?? self::DEFAULT_MPESA_CHECKOUT_ID,
            mpesaReceiptNumber: $row['mpesa_receipt_number'] ?? null,
            callbackReceivedAt: $callbackReceivedAt,
            initiatedAt: $initiatedAt
        );
    }
    
    private function mapDerivTransfer(TransactionId $transactionId, array $row): ?DerivTransfer
    {
        if (empty($row['deriv_transfer_id'])) {
            return null;
        }
        
        // Validate required DerivTransfer fields
        $requiredFields = ['deriv_from_login_id', 'deriv_to_login_id', 'deriv_amount_usd_cents'];
        foreach ($requiredFields as $field) {
            if (empty($row[$field])) {
                throw new InvalidArgumentException(
                    "DerivTransfer requires {$field}"
                );
            }
        }
        
        $executedAt = null;
        if (!empty($row['deriv_executed_at'])) {
            try {
                $executedAt = new DateTimeImmutable($row['deriv_executed_at']);
            } catch (\Exception $e) {
                error_log("Invalid deriv_executed_at: " . $e->getMessage());
            }
        } elseif (!empty($row['updated_at'])) {
            $executedAt = new DateTimeImmutable($row['updated_at']);
        } else {
            $executedAt = new DateTimeImmutable();
        }
        
        // Parse raw payload
        $rawResponse = [];
        if (!empty($row['deriv_raw_payload'])) {
            $decoded = json_decode((string)$row['deriv_raw_payload'], true);
            $rawResponse = is_array($decoded) ? $decoded : [];
        }
        
        // Determine if it's a deposit or withdrawal based on transaction type
        $isDeposit = false;
        if (isset($row['type'])) {
            $normalizedType = strtolower(trim($row['type']));
            $isDeposit = $normalizedType === 'deposit';
        }
        
        // Get withdrawal verification code
        $withdrawalVerificationCode = $row['withdrawal_code'] ?? null;
        
        if ($isDeposit) {
            return DerivTransfer::forDeposit(
                transactionId: $transactionId,
                paymentAgentLoginId: $row['deriv_from_login_id'], // From login should be payment agent
                userDerivLoginId: $row['deriv_to_login_id'], // To login should be user
                amountUsd: Money::usd((int)$row['deriv_amount_usd_cents']),
                derivTransferId: $row['deriv_transfer_id'],
                derivTxnId: $row['deriv_txn_id'] ?? $row['deriv_transfer_id'],
                executedAt: $executedAt,
                rawResponse: $rawResponse
            );
        } else {
            // Withdrawal
            if (empty($withdrawalVerificationCode)) {
                $withdrawalVerificationCode = $row['withdrawal_verification_code'] ?? null;
            }

            if (empty($withdrawalVerificationCode) || !preg_match('/^[A-Z0-9]{6}$/', $withdrawalVerificationCode)) {
                return null; 
            }
            
            return DerivTransfer::forWithdrawal(
                transactionId: $transactionId,
                userDerivLoginId: $row['deriv_from_login_id'],
                paymentAgentLoginId: $row['deriv_to_login_id'],
                amountUsd: Money::usd((int)$row['deriv_amount_usd_cents']),
                derivTransferId: $row['deriv_transfer_id'],
                derivTxnId: $row['deriv_txn_id'] ?? $row['deriv_transfer_id'],
                withdrawalVerificationCode: $withdrawalVerificationCode, 
                executedAt: $executedAt,
                rawResponse: $rawResponse
            );
        }
    }
    
    private function setAdditionalProperties(TxAggregate $transaction, array $row): void
    {
        // Use reflection to set properties that aren't in the reconstitute method
        $reflectionClass = new \ReflectionClass($transaction);
        
        // Set userDerivLoginId if it exists
        if (isset($row['user_deriv_login_id']) && $row['user_deriv_login_id']) {
            $property = $reflectionClass->getProperty('userDerivLoginId');
            $property->setAccessible(true);
            $property->setValue($transaction, $row['user_deriv_login_id']);
        }
        
        // Set withdrawalVerificationCode if it exists
        if (isset($row['withdrawal_verification_code']) && $row['withdrawal_verification_code']) {
            $property = $reflectionClass->getProperty('withdrawalVerificationCode');
            $property->setAccessible(true);
            $property->setValue($transaction, $row['withdrawal_verification_code']);
        }
        
        // Set mpesaDisbursement if it exists
        $mpesaDisbursement = $this->mapMpesaDisbursement($transaction->id(), $row);
        if ($mpesaDisbursement !== null) {
            $property = $reflectionClass->getProperty('mpesaDisbursement');
            $property->setAccessible(true);
            $property->setValue($transaction, $mpesaDisbursement);
        }
        
        // Set failure reason if it exists
        if (isset($row['failure_reason']) && $row['failure_reason']) {
            $property = $reflectionClass->getProperty('failureReason');
            $property->setAccessible(true);
            $property->setValue($transaction, $row['failure_reason']);
        }
        
        // Set provider error if it exists
        if (isset($row['provider_error']) && $row['provider_error']) {
            $property = $reflectionClass->getProperty('providerError');
            $property->setAccessible(true);
            $property->setValue($transaction, $row['provider_error']);
        }
        
        // Set retry count if it exists
        if (isset($row['retry_count'])) {
            $property = $reflectionClass->getProperty('retryCount');
            $property->setAccessible(true);
            $property->setValue($transaction, (int)$row['retry_count']);
        }
    }
    
    private function mapMpesaDisbursement(TransactionId $transactionId, array $row): ?MpesaDisbursement
    {
        // Check for disbursement indicators
        if (empty($row['disb_conversation_id']) && empty($row['disb_mpesa_receipt_number'])) {
            return null;
        }
        
        // Validate required fields
        $requiredFields = ['disb_phone_number', 'disb_amount_kes_cents'];
        foreach ($requiredFields as $field) {
            if (empty($row[$field])) {
                return null; // Don't throw, just return null if incomplete
            }
        }
        
        try {
            $callbackReceivedAt = null;
            if (!empty($row['disb_callback_received_at'])) {
                $callbackReceivedAt = new DateTimeImmutable($row['disb_callback_received_at']);
            }
            
            $initiatedAt = null;
            if (!empty($row['disb_initiated_at'])) {
                $initiatedAt = new DateTimeImmutable($row['disb_initiated_at']);
            } else {
                $initiatedAt = new DateTimeImmutable();
            }
            
            // Parse raw payload
            $rawPayload = [];
            if (!empty($row['disb_raw_payload'])) {
                $decoded = json_decode((string)$row['disb_raw_payload'], true);
                $rawPayload = is_array($decoded) ? $decoded : [];
            }
            
            return new MpesaDisbursement(
                transactionId: $transactionId,
                conversationId: $row['disb_conversation_id'] ?? '',
                originatorConversationId: $row['disb_originator_conversation_id'] ?? '',
                phoneNumber: PhoneNumber::fromE164($row['disb_phone_number']),
                amountKes: Money::kes((int)$row['disb_amount_kes_cents']),
                mpesaReceiptNumber: $row['disb_mpesa_receipt_number'] ?? null,
                resultCode: $row['disb_result_code'] ?? null,
                resultDescription: $row['disb_result_description'] ?? null,
                callbackReceivedAt: $callbackReceivedAt,
                initiatedAt: $initiatedAt,
                rawPayload: $rawPayload
            );
        } catch (\Exception $e) {
            // Log error but don't fail the entire mapping
            error_log("Failed to map MpesaDisbursement: " . $e->getMessage());
            return null;
        }
    }
}