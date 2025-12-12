<?php
declare(strict_types=1);

namespace PenPay\Workers\Deposit;

use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Domain\Payments\Entity\MpesaRequest;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Shared\Kernel\TransactionId;
use Psr\Log\LoggerInterface;

final class MpesaCallbackWorker
{
    public function __construct(
        private readonly TransactionRepositoryInterface $txRepo,
        private readonly RedisStreamPublisherInterface $publisher,
        private readonly LoggerInterface $logger
    ) {}

    public function handle(array $verified): void
    {
        // Convert string to TransactionId
        try {
            $txId = TransactionId::fromString($verified['transaction_id']);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('MpesaCallbackWorker: invalid transaction ID format', [
                'transaction_id' => $verified['transaction_id'] ?? 'missing',
                'error' => $e->getMessage()
            ]);
            return;
        }

        try {
            $transaction = $this->txRepo->getById($txId);
        } catch (\Exception $e) {
            $this->logger->error('MpesaCallbackWorker: transaction not found or error loading', [
                'transaction_id' => (string)$txId,
                'error' => $e->getMessage()
            ]);
            return;
        }

        // Idempotency: if already finalized
        if ($transaction->status()->isCompleted() || $transaction->status()->isFailed()) {
            $this->logger->info('Callback ignored: transaction already finalized', [
                'transaction_id' => (string)$txId,
                'current_status' => $transaction->status()->value
            ]);
            return;
        }

        if ($verified['status'] === 'success') {
            // Convert amount_kes_cents to Money object
            $amountKes = Money::kes($verified['amount_kes_cents']);
            
            // Convert phone string to PhoneNumber object
            $phoneNumber = PhoneNumber::fromKenyan($verified['phone']);
            
            $mpesaRequest = MpesaRequest::fromCallback(
                checkoutRequestId: $verified['checkout_request_id'],
                mpesaReceiptNumber: $verified['mpesa_receipt'],
                phoneNumber: $phoneNumber,
                amountKes: $amountKes, 
                callbackReceivedAt: new \DateTimeImmutable()
            );

            $transaction->recordMpesaCallback($mpesaRequest);
            $this->txRepo->save($transaction);

            $this->publisher->publish('deposits.mpesa_confirmed', [
                'transaction_id' => (string)$txId,
                'mpesa_receipt' => $verified['mpesa_receipt'],
                'amount_kes_cents' => $verified['amount_kes_cents'],
                'phone' => $verified['phone'],
            ]);

            $this->logger->info('M-Pesa deposit confirmed', ['transaction_id' => (string)$txId]);
            return;
        }

        // Failed
        $transaction->fail('mpesa_user_cancelled');
        $this->txRepo->save($transaction);

        $this->publisher->publish('deposits.failed', [
            'transaction_id' => (string)$txId,
            'reason' => 'user_cancelled_or_timeout',
            'result_code' => $verified['result_code'] ?? 'unknown',
        ]);

        $this->logger->warning('M-Pesa deposit failed', [
            'transaction_id' => (string)$txId,
            'result_code' => $verified['result_code'] ?? 'unknown'
        ]);
    }
}