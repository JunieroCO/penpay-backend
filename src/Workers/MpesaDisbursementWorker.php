<?php
declare(strict_types=1);

namespace PenPay\Workers;

use PenPay\Domain\Payments\Aggregate\WithdrawalTransaction;
use PenPay\Domain\Payments\Repository\WithdrawalTransactionRepositoryInterface;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Infrastructure\Mpesa\MpesaGatewayInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use Psr\Log\LoggerInterface;
use DateTimeImmutable;

final class MpesaDisbursementWorker
{
    public function __construct(
        private readonly WithdrawalTransactionRepositoryInterface $txRepo,
        private readonly MpesaGatewayInterface $mpesaGateway,
        private readonly RedisStreamPublisherInterface $publisher,
        private readonly LoggerInterface $logger,
        private readonly int $maxRetries = 3
    ) {}

    /**
     * Handles: withdrawals.deriv_debited
     *
     * @param array{transaction_id: string} $payload
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
            $this->logger->warning('MpesaDisbursementWorker: invalid transaction_id format', [
                'transaction_id' => $txIdString
            ]);
            return;
        }

        $tx = $this->txRepo->getById($txId);
        if (!$tx instanceof WithdrawalTransaction) {
            $this->logger->warning('MpesaDisbursementWorker: transaction not found or wrong type', [
                'transaction_id' => $txIdString
            ]);
            return;
        }

        // Idempotency + state machine guard
        if ($tx->isFinalized()) {
            $this->logger->info('MpesaDisbursementWorker: already finalized, skipping', [
                'transaction_id' => $txIdString
            ]);
            return;
        }

        if ($tx->derivWithdrawal() === null) {
            $this->failAndPublish($tx, 'deriv_debit_missing', 'Deriv debit not recorded');
            return;
        }

        $exchangeRate = $tx->exchangeRate();
        if ($exchangeRate === null) {
            $this->failAndPublish($tx, 'exchange_rate_missing', 'Locked FX rate not set');
            return;
        }

        // Convert USD → KES (locked rate)
        $usdAmount = $tx->amountUsd()->toDecimal();
        $kesCents = (int) round($usdAmount * $exchangeRate * 100);

        if ($kesCents <= 0) {
            $this->failAndPublish($tx, 'invalid_kes_amount', 'Calculated KES amount is zero or negative');
            return;
        }

        $this->logger->info('MpesaDisbursementWorker: initiating B2C disbursement', [
            'transaction_id' => (string)$txId,
            'usd'           => $usdAmount,
            'rate'          => $exchangeRate,
            'kes_cents'     => $kesCents,
            'kes'           => $kesCents / 100,
        ]);

        try {
            $result = $this->mpesaGateway->b2c(
                phoneNumber: $tx->userId(), // assuming userId is phone, or replace with proper field
                amountKesCents: $kesCents,
                reference: (string)$txId
            );

            // DEBUG: Add comprehensive logging
            $this->logger->debug('MpesaDisbursementWorker: B2C result received', [
                'success' => $result->isSuccess(),
                'result_code' => $result->resultCode(),
                'error_message' => $result->errorMessage(),
                'receipt' => $result->receiptNumber(),
                'raw_response' => $result->raw()
            ]);

            if (!$result->isSuccess()) {
                $this->logger->warning('MpesaDisbursementWorker: B2C failed', [
                    'error' => $result->errorMessage(),
                    'result_code' => $result->resultCode()
                ]);
                $this->failAndPublish(
                    $tx,
                    'mpesa_b2c_failed',
                    $result->errorMessage() ?? 'Unknown M-Pesa error'
                );
                return;
            }

            $this->logger->debug('MpesaDisbursementWorker: Recording M-Pesa disbursement');

            // Try to record the disbursement
            $tx->recordMpesaDisbursement(
                mpesaReceipt: $result->receiptNumber(),
                resultCode: $result->resultCode(),
                executedAt: new DateTimeImmutable(),
                amountKes: Money::kes($kesCents),
                rawResponse: $result->raw()
            );

            $this->logger->debug('MpesaDisbursementWorker: Saving transaction');

            $this->txRepo->save($tx);

            $this->logger->debug('MpesaDisbursementWorker: Publishing completion event');

            $this->publisher->publish('withdrawals.completed', [
                'transaction_id' => (string)$tx->id(),
                'mpesa_receipt'  => $result->receiptNumber(),
                'amount_kes'     => $kesCents / 100,
                'completed_at'   => (new DateTimeImmutable())->format('c'),
            ]);

            $this->logger->info('MpesaDisbursementWorker: withdrawal completed successfully', [
                'transaction_id' => (string)$txId,
                'mpesa_receipt'  => $result->receiptNumber(),
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('MpesaDisbursementWorker: exception during B2C', [
                'transaction_id' => (string)$txId,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            $this->failAndPublish($tx, 'mpesa_disbursement_exception', $e->getMessage());
        }
    }

    private function failAndPublish(WithdrawalTransaction $tx, string $reason, string $detail): void
    {
        $this->logger->warning('MpesaDisbursementWorker: Failing transaction', [
            'transaction_id' => (string)$tx->id(),
            'reason' => $reason,
            'detail' => $detail
        ]);

        $tx->fail($reason, $detail);
        $this->txRepo->save($tx);

        $this->publisher->publish('withdrawals.failed', [
            'transaction_id' => (string)$tx->id(),
            'reason'         => $reason,
            'detail'         => $detail,
            'failed_at'      => (new DateTimeImmutable())->format('c'),
        ]);
    }
}