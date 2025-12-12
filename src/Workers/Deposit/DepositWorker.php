<?php
declare(strict_types=1);

namespace PenPay\Workers\Deposit;

use PenPay\Infrastructure\Mpesa\Deposit\MpesaClientInterface;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Domain\Payments\Entity\MpesaRequest;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use Psr\Log\LoggerInterface;
use Throwable;

final class DepositWorker
{
    private const STREAM_MPESAREQUEST_INITIATED = 'deposits.mpesa_requested';
    private const STREAM_DEPOSIT_INITIATED = 'deposits.initiated';

    public function __construct(
        private readonly TransactionRepositoryInterface $txRepo,
        private readonly MpesaClientInterface $mpesaClient,
        private readonly RedisStreamPublisherInterface $publisher,
        private readonly LoggerInterface $logger,
        private readonly int $maxRetries = 3,
    ) {}

    public function handle(array $message): void
    {
        $txIdString = $message['transaction_id'] ?? null;
        if (!$txIdString) {
            $this->logger->warning('deposit.initiated missing transaction_id', ['payload' => $message]);
            return;
        }

        try {
            $txId = TransactionId::fromString($txIdString);
        } catch (\InvalidArgumentException) {
            $this->logger->error('Invalid transaction_id format', ['transaction_id' => $txIdString]);
            return;
        }

        $transaction = $this->txRepo->getById($txId);

        // IDEMPOTENCY: Already processed?
        if (!$transaction->status()->isPending()) {
            $this->logger->info('STK push already initiated or completed', [
                'transaction_id' => (string)$txId,
                'status' => $transaction->status()->value,
            ]);
            return;
        }

        $kesCents = (int) ($message['amount_kes_cents'] ?? $message['amount_kes'] ?? 0);
        $phoneRaw = $message['phone'] ?? null;

        if ($kesCents <= 0) {
            $this->failTransaction($transaction, 'invalid_amount_in_payload');
            return;
        }

        if (!$phoneRaw) {
            $this->failTransaction($transaction, 'missing_phone_number');
            return;
        }

        try {
            $phone = PhoneNumber::fromKenyan($phoneRaw);
        } catch (\InvalidArgumentException $e) {
            $this->failTransaction($transaction, 'invalid_phone_format', $e->getMessage());
            return;
        }

        $attempt = 0;
        while ($attempt < $this->maxRetries) {
            $attempt++;

            try {
                $this->logger->info('Initiating STK Push', [
                    'transaction_id' => (string)$txId,
                    'attempt' => $attempt,
                    'phone' => $phone->toE164(),
                    'amount_kes_cents' => $kesCents,
                ]);

                // M-Pesa Daraja API expects 2547xxxxxxxx format (without +)
                $phoneForMpesa = substr($phone->toE164(), 1); // Remove the + sign
                
                $response = $this->mpesaClient->initiateStkPush(
                    phoneNumber: $phoneForMpesa,
                    amountKesCents: $kesCents,
                    transactionId: (string)$txId,
                    callbackUrl: $_ENV['MPESA_CALLBACK_URL'] ?? 'https://api.penpay.africa/mpesa/callback'
                );

                // Update transaction state first
                $transaction->markStkPushInitiated();
                $transaction->markAwaitingMpesaCallback();

                // Create immutable MpesaRequest entity
                $mpesaRequest = MpesaRequest::initiated(
                    transactionId: $transaction->id(),
                    checkoutRequestId: $response->CheckoutRequestID,
                    phoneNumber: $phone,
                    amountKes: Money::kes($kesCents),
                    merchantRequestId: $response->MerchantRequestID ?? null,
                    rawPayload: (array) $response
                );

                // Record in domain
                $transaction->recordMpesaCallback($mpesaRequest);
                $this->txRepo->save($transaction);

                // Publish next step
                $this->publisher->publish(self::STREAM_MPESAREQUEST_INITIATED, [
                    'transaction_id' => (string)$txId,
                    'checkout_request_id' => $response->CheckoutRequestID,
                    'phone' => $phone->toE164(),
                    'amount_kes_cents' => $kesCents,
                    'merchant_request_id' => $response->MerchantRequestID ?? null,
                    'timestamp' => time(),
                ]);

                $this->logger->info('STK Push initiated successfully', [
                    'transaction_id' => (string)$txId,
                    'checkout_request_id' => $response->CheckoutRequestID,
                    'phone' => $phone->toE164(),
                ]);

                return; // SUCCESS

            } catch (Throwable $e) {
                $this->logger->warning('STK Push failed', [
                    'transaction_id' => (string)$txId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);

                if ($attempt >= $this->maxRetries) {
                    $this->failTransaction($transaction, 'mpesa_stk_push_failed', $e->getMessage());
                    return;
                }

                // Exponential backoff
                usleep(500_000 * $attempt);
            }
        }
    }

    private function failTransaction(
        \PenPay\Domain\Payments\Aggregate\Transaction $transaction,
        string $reason,
        ?string $providerError = null
    ): void {
        $transaction->fail($reason, $providerError);
        $this->txRepo->save($transaction);

        $this->publisher->publish('deposits.failed', [
            'transaction_id' => (string)$transaction->id(),
            'user_id' => $transaction->userId(),
            'reason' => $reason,
            'provider_error' => $providerError,
            'timestamp' => time(),
        ]);

        $this->logger->error('Deposit failed permanently', [
            'transaction_id' => (string)$transaction->id(),
            'reason' => $reason,
            'provider_error' => $providerError,
        ]);
    }
}