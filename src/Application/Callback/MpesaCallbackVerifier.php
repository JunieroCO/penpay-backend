<?php
declare(strict_types=1);

namespace PenPay\Application\Callback;

use PenPay\Domain\Shared\Kernel\TransactionId;
use RuntimeException;

final class MpesaCallbackVerifier
{
    public function verify(array $payload): array
    {
        $stk = $payload['Body']['stkCallback'] ?? null;
        if (!$stk) {
            throw new RuntimeException('Invalid M-Pesa callback: missing Body.stkCallback');
        }

        $resultCode = $stk['ResultCode'] ?? null;
        $checkoutRequestId = $stk['CheckoutRequestID'] ?? null;
        if ($resultCode === null || $checkoutRequestId === null) {
            throw new RuntimeException('Missing ResultCode or CheckoutRequestID');
        }

        $metadata = $stk['CallbackMetadata']['Item'] ?? [];

        $normalized = $this->normalizeMetadata($metadata);

        if (!isset($normalized['transaction_id'])) {
            throw new RuntimeException('transaction_id not found in callback metadata');
        }

        // Validate TransactionId format
        TransactionId::fromString($normalized['transaction_id']);

        $isSuccess = (int)$resultCode === 0;

        return [
            'transaction_id'   => $normalized['transaction_id'],
            'status'           => $isSuccess ? 'success' : 'failed',
            'mpesa_receipt'    => $isSuccess ? ($normalized['mpesa_receipt'] ?? null) : null,
            'phone'            => $normalized['phone'] ?? '',
            'amount_kes_cents' => $isSuccess ? (int)($normalized['amount'] * 100) : 0,
            'checkout_request_id' => $checkoutRequestId,
            'result_code'      => (int)$resultCode,
            'result_desc'      => $stk['ResultDesc'] ?? '',
            'raw'              => $payload,
        ];
    }

    private function normalizeMetadata(array $items): array
    {
        $data = [];
        foreach ($items as $item) {
            $name = $item['Name'] ?? null;
            $value = $item['Value'] ?? null;
            if ($name === null || $value === null) continue;

            match ($name) {
                'Amount'              => $data['amount'] = (float)$value,
                'MpesaReceiptNumber'  => $data['mpesa_receipt'] = (string)$value,
                'PhoneNumber'         => $data['phone'] = (string)$value,
                'Balance'             => null,
                'TransactionID',
                'transaction_id'      => $data['transaction_id'] = (string)$value,
                default               => null,
            };
        }
        return $data;
    }
}