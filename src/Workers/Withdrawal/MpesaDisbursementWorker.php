<?php
declare(strict_types=1);

namespace PenPay\Workers\Withdrawal;

use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\ValueObject\TransactionType;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\Entity\MpesaDisbursement;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use PenPay\Infrastructure\Mpesa\Withdrawal\MpesaGatewayInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use Psr\Log\LoggerInterface;
use DateTimeImmutable;

final class MpesaDisbursementWorker
{
    private const STREAM_COMPLETED = 'withdrawals.completed';
    private const STREAM_FAILED    = 'withdrawals.failed';

    public function __construct(
        private readonly TransactionRepositoryInterface $txRepo,
        private readonly MpesaGatewayInterface $mpesaGateway,
        private readonly RedisStreamPublisherInterface $publisher,
        private readonly LoggerInterface $logger,
        private readonly int $maxRetries = 3
    ) {}

    /**
     * Handles: withdrawals.deriv_debited
     * Processes withdrawals in AWAITING_MPESA_DISBURSEMENT state
     */
    public function handle(array $payload): void
    {
        $txIdString = $payload['transaction_id'] ?? null;

        if (!$txIdString) {
            $this->logger->warning('MpesaDisbursementWorker: missing transaction_id', $payload);
            return;
        }

        try {
            $txId = TransactionId::fromString($txIdString);
        } catch (\Throwable) {
            $this->logger->warning('MpesaDisbursementWorker: invalid transaction_id', [
                'transaction_id' => $txIdString
            ]);
            return;
        }

        $tx = $this->txRepo->getById($txId);

        if (!$tx instanceof Transaction) {
            $this->logger->warning('MpesaDisbursementWorker: transaction not found', [
                'transaction_id' => $txIdString
            ]);
            return;
        }

        // Only process withdrawals
        if ($tx->type() !== TransactionType::WITHDRAWAL) {
            $this->logger->warning('MpesaDisbursementWorker: not a withdrawal transaction', [
                'transaction_id' => (string)$txId,
                'type' => $tx->type()->value
            ]);
            return;
        }

        // Skip if already has M-Pesa disbursement (idempotency)
        if ($tx->hasMpesaDisbursement()) {
            $this->logger->info('MpesaDisbursementWorker: M-Pesa disbursement already recorded, skipping', [
                'transaction_id' => (string)$txId,
                'status' => $tx->status()->value
            ]);
            return;
        }

        // Only process withdrawals awaiting M-Pesa disbursement
        if (!$tx->status()->isAwaitingMpesaDisbursement()) {
            $this->logger->warning('MpesaDisbursementWorker: transaction not in correct state', [
                'transaction_id' => (string)$txId,
                'status' => $tx->status()->value,
                'expected' => 'AWAITING_MPESA_DISBURSEMENT'
            ]);
            return;
        }

        if ($tx->derivTransfer() === null) {
            $this->failAndPublish($tx, 'deriv_debit_missing', 'Deriv debit not recorded');
            return;
        }

        $lockedRate = $tx->lockedRate();
        if ($lockedRate === null) {
            $this->failAndPublish($tx, 'exchange_rate_missing', 'Locked rate not found');
            return;
        }

        $kesCents = (int) round($tx->amountUsd()->toDecimal() * $lockedRate->rate * 100);

        if ($kesCents <= 0) {
            $this->failAndPublish($tx, 'invalid_kes_amount', 'KES amount calculated as zero');
            return;
        }

        // Check minimum B2C amount (KES 50)
        if ($kesCents < 5000) {
            $this->failAndPublish($tx, 'amount_below_minimum', 'KES amount below M-Pesa B2C minimum of 50.00');
            return;
        }

        $this->logger->info('Initiating M-Pesa B2C disbursement', [
            'transaction_id' => (string)$txId,
            'usd_amount'     => $tx->amountUsd()->toDecimal(),
            'kes_cents'      => $kesCents,
            'rate'           => $lockedRate->rate,
            'user_phone'     => $tx->userId(), // TODO: Get actual phone from user profile
        ]);

        try {
            $result = $this->mpesaGateway->b2c(
                phoneNumber:    $tx->userId(), // TODO: Replace with real phone from user profile
                amountKesCents: $kesCents,
                reference:      (string)$txId
            );

            $this->logger->debug('M-Pesa B2C response received', [
                'transaction_id' => (string)$txId,
                'success'        => $result->isSuccess(),
                'result_code'    => $result->resultCode(),
                'result_desc'    => $result->errorMessage(),
                'receipt'        => $result->receiptNumber() ?? 'N/A',
            ]);

            if (!$result->isSuccess()) {
                $this->handleMpesaFailure($tx, $result, $kesCents);
                return;
            }

            // Get raw response for metadata
            $rawResponse = $result->raw();

            
            $disbursement = MpesaDisbursement::fromArray([
                'transaction_id'             => (string)$tx->id(), 
                'conversation_id'            => $rawResponse['ConversationID'] ?? '',
                'originator_conversation_id' => $rawResponse['OriginatorConversationID'] ?? '',
                'phone_number'               => $rawResponse['PhoneNumber'] ?? $tx->userId(),
                'amount_kes_cents'           => $kesCents,
                'mpesa_receipt_number'       => $result->receiptNumber(), 
                'result_code'                => (string)($result->resultCode() ?? ''),
                'result_description'         => $result->errorMessage() ?? '',
                'raw_payload'                => $rawResponse,
                'completed_at'               => (new DateTimeImmutable())->format('c'),
            ]);

            // Record the disbursement (will mark transaction as COMPLETED if successful)
            $tx->recordMpesaDisbursement($disbursement);
            $this->txRepo->save($tx);

            if ($disbursement->isSuccessful()) {
                $this->publisher->publish(self::STREAM_COMPLETED, [
                    'transaction_id' => (string)$tx->id(),
                    'mpesa_receipt'  => $result->receiptNumber(),
                    'amount_kes'     => $kesCents / 100,
                    'completed_at'   => (new DateTimeImmutable())->format('c'),
                ]);

                $this->logger->info('Withdrawal fully completed — funds disbursed via M-Pesa', [
                    'transaction_id' => (string)$txId,
                    'mpesa_receipt'  => $result->receiptNumber(),
                    'amount_kes'     => $kesCents / 100,
                ]);

                return; 
            }

            $this->logger->error('M-Pesa disbursement recorded but not marked as successful', [
                'transaction_id' => (string)$txId,
                'result_code'    => $disbursement->resultCode,
                'result_desc'    => $disbursement->resultDescription,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('M-Pesa disbursement failed with exception', [
                'transaction_id' => (string)$txId,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            $this->failAndPublish($tx, 'mpesa_disbursement_exception', $e->getMessage());
        }
    }

    private function handleMpesaFailure(Transaction $tx, $result, int $kesCents): void
    {
        $errorMessage = $result->errorMessage() ?? 'M-Pesa B2C failed without description';
        
        // Check if transaction can be retried
        if ($tx->canRetry($this->maxRetries)) {
            $tx->incrementRetryCount();
            $this->txRepo->save($tx);
            
            $this->logger->warning('M-Pesa B2C failed, will retry', [
                'transaction_id' => (string)$tx->id(),
                'error'          => $errorMessage,
                'retry_count'    => $tx->retryCount(),
                'max_retries'    => $this->maxRetries,
            ]);
            
            // Transaction stays in AWAITING_MPESA_DISBURSEMENT for retry
        } else {
            // Max retries exceeded, fail the transaction
            $this->logger->error('M-Pesa B2C failed, max retries exceeded', [
                'transaction_id' => (string)$tx->id(),
                'error'          => $errorMessage,
                'retry_count'    => $tx->retryCount(),
            ]);
            
            $this->failAndPublish(
                $tx,
                'mpesa_b2c_failed',
                $errorMessage
            );
        }
    }

    private function failAndPublish(Transaction $tx, string $reason, string $detail): void
    {
        $this->logger->warning('Failing withdrawal transaction', [
            'transaction_id' => (string)$tx->id(),
            'reason'         => $reason,
            'detail'         => $detail,
            'status'         => $tx->status()->value,
        ]);

        try {
            $tx->fail($reason, $detail);
            $this->txRepo->save($tx);

            $this->publisher->publish(self::STREAM_FAILED, [
                'transaction_id' => (string)$tx->id(),
                'reason'         => $reason,
                'detail'         => $detail,
                'failed_at'      => (new DateTimeImmutable())->format('c'),
                'retry_count'    => $tx->retryCount(),
            ]);
        } catch (\DomainException $e) {
            // Transaction might already be in terminal state
            $this->logger->error('Failed to mark transaction as failed', [
                'transaction_id' => (string)$tx->id(),
                'error'          => $e->getMessage(),
                'current_status' => $tx->status()->value,
            ]);
        }
    }
}