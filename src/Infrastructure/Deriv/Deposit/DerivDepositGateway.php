<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Deriv\Deposit;

use PenPay\Domain\Payments\Entity\DerivTransferResult;
use PenPay\Infrastructure\DerivWsGateway\WsClientInterface;
use React\Promise\PromiseInterface;

final class DerivDepositGateway implements DerivDepositGatewayInterface
{
    public function __construct(
        private readonly WsClientInterface $wsClient
    ) {}

    public function deposit(
        string $loginId,
        float $amountUsd,
        string $paymentAgentToken,
        string $reference,
        array $metadata = []
    ): PromiseInterface {
        $reqId = $this->wsClient->nextReqId();

        // Correct payload according to Deriv API documentation
        $payload = [
            'paymentagent_transfer' => 1,
            'amount'               => $amountUsd,
            'currency'             => 'USD',
            'transfer_to'          => $loginId, 
            'description'          => $reference,
            'req_id'               => $reqId,
        ];

        // Add metadata if provided
        if (!empty($metadata)) {
            $payload['passthrough'] = $metadata;
        }

        $this->wsClient->getLogger()->info('DerivDepositGateway: Initiating payment agent transfer', [
            'transfer_to' => $loginId,
            'amount_usd' => $amountUsd,
            'reference' => $reference,
            'req_id' => $reqId,
        ]);

        return $this->wsClient->sendAndWait($payload)
            ->then(
                // Success handler
                function (array $response) use ($amountUsd, $reqId, $loginId) {
                    $this->wsClient->getLogger()->info('DerivDepositGateway: Transfer response received', [
                        'req_id' => $reqId,
                        'transfer_to' => $loginId,
                        'has_error' => isset($response['error']),
                        'msg_type' => $response['msg_type'] ?? 'unknown',
                    ]);

                    if (isset($response['error'])) {
                        $errorMessage = $response['error']['message'] ?? 'Unknown Deriv API error';
                        $this->wsClient->getLogger()->error('DerivDepositGateway: Transfer failed', [
                            'req_id' => $reqId,
                            'transfer_to' => $loginId,
                            'error' => $errorMessage,
                            'response' => $response,
                        ]);
                        
                        return DerivTransferResult::failure($errorMessage, $response);
                    }

                    // Check if this is a successful paymentagent_transfer response
                    if (($response['msg_type'] ?? '') !== 'paymentagent_transfer') {
                        $this->wsClient->getLogger()->error('DerivDepositGateway: Unexpected response type', [
                            'req_id' => $reqId,
                            'transfer_to' => $loginId,
                            'expected_msg_type' => 'paymentagent_transfer',
                            'actual_msg_type' => $response['msg_type'] ?? 'unknown',
                            'response' => $response,
                        ]);
                        return DerivTransferResult::failure('Unexpected response type from Deriv API', $response);
                    }

                    // Check if the transfer was successful (1 = success, 2 = dry-run success)
                    $transferStatus = $response['paymentagent_transfer'] ?? 0;
                    if ($transferStatus !== 1 && $transferStatus !== 2) {
                        $this->wsClient->getLogger()->error('DerivDepositGateway: Transfer not successful', [
                            'req_id' => $reqId,
                            'transfer_to' => $loginId,
                            'transfer_status' => $transferStatus,
                            'response' => $response,
                        ]);
                        return DerivTransferResult::failure('Transfer was not successful', $response);
                    }

                    // Extract transfer details from successful response
                    $transactionId = $response['transaction_id'] ?? null;
                    $clientFullName = $response['client_to_full_name'] ?? null;
                    $clientLoginId = $response['client_to_loginid'] ?? null;

                    // Check if we have the essential transaction ID
                    if (!$transactionId) {
                        $this->wsClient->getLogger()->error('DerivDepositGateway: Missing transaction ID in response', [
                            'req_id' => $reqId,
                            'transfer_to' => $loginId,
                            'response' => $response,
                        ]);
                        return DerivTransferResult::failure('Missing transaction ID in response', $response);
                    }

                    $this->wsClient->getLogger()->info('DerivDepositGateway: Transfer successful', [
                        'req_id' => $reqId,
                        'transfer_to' => $loginId,
                        'transaction_id' => $transactionId,
                        'client_full_name' => $clientFullName,
                        'client_loginid' => $clientLoginId,
                        'transfer_status' => $transferStatus,
                        'amount_usd' => $amountUsd,
                    ]);

                    return DerivTransferResult::success(
                        transferId: (string)$transactionId, 
                        txnId: (string)$transactionId,      
                        amountUsd: $amountUsd,
                        rawResponse: $response
                    );
                },
                // Error handler
                function (\Throwable $error) use ($reqId, $loginId) {
                    $this->wsClient->getLogger()->error('DerivDepositGateway: Transfer request failed', [
                        'req_id' => $reqId,
                        'transfer_to' => $loginId,
                        'error' => $error->getMessage(),
                        'exception' => get_class($error),
                    ]);

                    return DerivTransferResult::failure(
                        'Transfer request failed: ' . $error->getMessage(),
                        ['error' => $error->getMessage(), 'exception' => get_class($error)]
                    );
                }
            );
    }
}