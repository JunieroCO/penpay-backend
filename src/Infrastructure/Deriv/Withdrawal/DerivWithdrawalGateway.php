<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Deriv\Withdrawal;

use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\Currency;
use PenPay\Domain\Payments\Entity\DerivResult;
use PenPay\Infrastructure\DerivWsGateway\WsClientInterface;
use React\Promise\PromiseInterface;

final class DerivWithdrawalGateway implements DerivWithdrawalGatewayInterface
{
    public function __construct(
        private readonly WsClientInterface $wsClient
    ) {}

    public function withdraw(
        string $loginId,
        float $amountUsd,
        string $verificationCode,
        string $reference
    ): PromiseInterface {
        if ($loginId === '' || $amountUsd <= 0 || $verificationCode === '') {
            throw new \InvalidArgumentException('Invalid withdrawal parameters');
        }

        // Convert float to Money object for consistent domain usage
        $moneyAmount = Money::fromDecimal($amountUsd, Currency::USD);

        $payload = [
            'paymentagent_withdraw' => 1,
            'loginid'               => $loginId,
            'amount'                => $amountUsd,
            'currency'              => 'USD',
            'verification_code'     => $verificationCode,
            'description'           => substr($reference, 0, 250),
            'req_id'                => $this->wsClient->nextReqId(),
        ];

        $this->wsClient->getLogger()->info('DerivWithdrawalGateway: Initiating withdrawal', [
            'loginid' => $loginId,
            'amount_usd' => $amountUsd,
            'reference' => $reference,
            'req_id' => $payload['req_id']
        ]);

        return $this->wsClient->sendAndWait($payload)
            ->then(
                function (array $response) use ($moneyAmount, $loginId, $payload) {
                    $reqId = $payload['req_id'];

                    if (isset($response['error'])) {
                        $code = $response['error']['code'] ?? 'UnknownError';
                        $msg  = $response['error']['message'] ?? 'Unknown error';

                        $mapped = match ($code) {
                            'InvalidToken' => 'Invalid or expired token',
                            'InsufficientFunds' => 'Insufficient balance',
                            'PaymentAgentWithdrawalError' => 'Withdrawal not allowed',
                            default => $msg,
                        };

                        $this->wsClient->getLogger()->error('Withdrawal failed', [
                            'req_id' => $reqId,
                            'error_code' => $code,
                            'message' => $mapped
                        ]);

                        return DerivResult::failure($mapped, $response);
                    }

                    // Correct path: response has top-level 'paymentagent_withdraw' => 1
                    if (!isset($response['paymentagent_withdraw']) || $response['paymentagent_withdraw'] !== 1) {
                        return DerivResult::failure('Withdrawal not confirmed by Deriv', $response);
                    }

                    $txnId = $response['transaction_id'] ?? null;
                    if (!$txnId) {
                        return DerivResult::failure('Missing transaction_id in response', $response);
                    }

                    $this->wsClient->getLogger()->info('Withdrawal successful', [
                        'req_id' => $reqId,
                        'loginid' => $loginId,
                        'transaction_id' => $txnId,
                        'amount_usd' => $moneyAmount->toDecimal()
                    ]);

                    return DerivResult::success(
                        transferId: (string)$txnId,
                        txnId: (string)$txnId,
                        amountUsd: $moneyAmount, // Pass Money object instead of float
                        rawResponse: $response
                    );
                },
                function (\Throwable $e) use ($payload) {
                    return DerivResult::failure(
                        'Network error: ' . $e->getMessage(),
                        ['exception' => get_class($e)]
                    );
                }
            );
    }
}