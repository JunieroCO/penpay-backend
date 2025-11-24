<?php
declare(strict_types=1);

namespace PenPay\Workers;

use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Domain\Payments\Entity\MpesaRequest;
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
        $txId = $verified['transaction_id'];

        $transaction = $this->txRepo->getById($txId);
        if (!$transaction) {
            $this->logger->error('MpesaCallbackWorker: transaction not found', ['transaction_id' => $txId]);
            return;
        }

        // Idempotency: if already finalized
        if ($transaction->getStatus()->isCompleted() || $transaction->getStatus()->isFailed()) {
            $this->logger->info('Callback ignored: transaction already finalized', [
                'transaction_id' => $txId,
                'current_status' => $transaction->getStatus()->value
            ]);
            return;
        }

        if ($verified['status'] === 'success') {
            $mpesaRequest = MpesaRequest::fromCallback(
                checkoutRequestId: $verified['checkout_request_id'],
                mpesaReceiptNumber: $verified['mpesa_receipt'],
                phoneNumber: $verified['phone'],
                amountKesCents: $verified['amount_kes_cents'],
                callbackReceivedAt: new \DateTimeImmutable()
            );

            $transaction->recordMpesaCallback($mpesaRequest);
            $this->txRepo->save($transaction);

            $this->publisher->publish('deposits.mpesa_confirmed', [
                'transaction_id' => $txId,
                'mpesa_receipt' => $verified['mpesa_receipt'],
                'amount_kes_cents' => $verified['amount_kes_cents'],
                'phone' => $verified['phone'],
            ]);

            $this->logger->info('M-Pesa deposit confirmed', ['transaction_id' => $txId]);
            return;
        }

        // Failed
        $transaction->fail('mpesa_user_cancelled');
        $this->txRepo->save($transaction);

        $this->publisher->publish('deposits.failed', [
            'transaction_id' => $txId,
            'reason' => 'user_cancelled_or_timeout',
            'result_code' => $verified['result_code'],
        ]);

        $this->logger->warning('M-Pesa deposit failed', [
            'transaction_id' => $txId,
            'result_code' => $verified['result_code']
        ]);
    }
}