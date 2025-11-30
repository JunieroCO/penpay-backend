<?php
declare(strict_types=1);

namespace PenPay\Workers\Deposit;

use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Infrastructure\Mpesa\Deposit\MpesaClientInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Domain\Payments\Entity\MpesaRequest;
use PenPay\Domain\Shared\Kernel\TransactionId;
use Psr\Log\LoggerInterface;
use Throwable;

final class DepositWorker
{
    public function __construct(
        private readonly TransactionRepositoryInterface $txRepo,
        private readonly MpesaClientInterface $mpesaClient,
        private readonly RedisStreamPublisherInterface $publisher,
        private readonly LoggerInterface $logger,
        private readonly int $maxRetries = 3
    ) {}

    public function handle(array $message): void
    {
        $txIdString = $message['transaction_id'] ?? null;
        if (!$txIdString) {
            $this->logger->warning('deposit.initiated missing transaction_id', $message);
            return;
        }

        try {
            $txId = TransactionId::fromString($txIdString);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('Invalid transaction ID format', ['id' => $txIdString]);
            return;
        }

        $transaction = $this->txRepo->getById($txId);
        if (!$transaction) {
            $this->logger->error('Transaction not found', ['transaction_id' => $txIdString]);
            return;
        }

        // IDEMPOTENCY: Skip if already processed
        if (!$transaction->getStatus()->isPending()) {
            $this->logger->info('STK push already processed', [
                'transaction_id' => $txIdString,
                'status' => $transaction->getStatus()->value
            ]);
            return;
        }

        $kesCents = $message['kes_cents'] ?? $message['amount_kes'] ?? null;
        $phone = $message['phone'] ?? '254712345678';

        if (!$kesCents || $kesCents <= 0) {
            $this->logger->error('Invalid amount', ['transaction_id' => $txIdString]);
            return;
        }

        $attempt = 0;
        while ($attempt < $this->maxRetries) {
            $attempt++;
            try {
                $this->logger->info('Attempting STK push', [
                    'transaction_id' => $txIdString,
                    'attempt' => $attempt,
                    'amount_kes_cents' => $kesCents
                ]);

                $response = $this->mpesaClient->initiateStkPush(
                    phoneNumber: $phone,
                    amountKesCents: (int)$kesCents,
                    transactionId: $txIdString,
                    callbackUrl: $_ENV['MPESA_CALLBACK_URL'] ?? 'https://api.penpay.africa/mpesa/callback'
                );

                $mpesaRequest = MpesaRequest::initiated(
                    transactionId: $transaction->getId(),
                    checkoutRequestId: $response->CheckoutRequestID,
                    phoneNumber: $phone,
                    amountKesCents: (int)$kesCents
                );

                $transaction->recordMpesaCallback($mpesaRequest);
                $this->txRepo->save($transaction);

                $this->publisher->publish('deposits.mpesa_requested', [
                    'transaction_id' => $txIdString,
                    'checkout_request_id' => $response->CheckoutRequestID,
                    'phone' => $phone,
                    'amount_kes_cents' => (int)$kesCents,
                ]);

                $this->logger->info('STK push successful', [
                    'transaction_id' => $txIdString,
                    'checkout_request_id' => $response->CheckoutRequestID
                ]);

                return;

            } catch (Throwable $e) {
                $this->logger->warning('STK push failed', [
                    'transaction_id' => $txIdString,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

                if ($attempt >= $this->maxRetries) {
                    $transaction->fail('mpesa_stk_push_failed');
                    $this->txRepo->save($transaction);

                    $this->publisher->publish('deposits.failed', [
                        'transaction_id' => $txIdString,
                        'reason' => 'mpesa_stk_push_failed',
                        'error' => $e->getMessage(),
                    ]);

                    $this->logger->error('STK push failed permanently', [
                        'transaction_id' => $txIdString,
                        'error' => $e->getMessage()
                    ]);
                } else {
                    usleep(500_000 * $attempt);
                }
            }
        }
    }
}