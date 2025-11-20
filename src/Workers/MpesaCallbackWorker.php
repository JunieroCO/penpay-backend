<?php
declare(strict_types=1);

namespace PenPay\Workers;

use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Entity\MpesaRequest;
use PenPay\Infrastructure\Persistence\TransactionRepositoryInterface;
use PenPay\Infrastructure\Queue\RedisStreamConsumer;
use DateTimeImmutable;

final class MpesaCallbackWorker
{
    public function __construct(
        private TransactionRepositoryInterface $transactionRepo,
        private RedisStreamConsumer $consumer
    ) {}

    public function run(): void
    {
        $this->consumer->consume('mpesa.callback', function (array $message) {
            $tx = $this->transactionRepo->findById($message['transaction_id']);
            if (!$tx || $tx->getStatus()->isMpesaConfirmed()) {
                return; // idempotent
            }

            $request = new MpesaRequest(
                transactionId: $tx->getId(),
                phoneNumber: $message['phone'],
                amountKes: $tx->getAmount(),
                merchantRequestId: $message['merchant_id'],
                checkoutRequestId: $message['checkout_id'],
                mpesaReceiptNumber: $message['receipt'],
                callbackReceivedAt: new DateTimeImmutable(),
                initiatedAt: new DateTimeImmutable()
            );

            $tx->recordMpesaCallback($request);
            $this->transactionRepo->save($tx);

            // Trigger Deriv transfer
            $this->publisher->publish('deriv.transfer.requested', [
                'transaction_id' => $tx->getId()->value,
                'deriv_login_id' => $message['deriv_login_id'],
                'usd_cents' => $message['usd_cents'],
            ]);
        });
    }
}