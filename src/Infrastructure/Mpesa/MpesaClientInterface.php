<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Mpesa;

interface MpesaClientInterface
{
    /**
     * @return object{
     *     CheckoutRequestID: string,
     *     MerchantRequestID?: string,
     *     ResponseCode: string,
     *     ResponseDescription: string,
     *     CustomerMessage?: string
     * }
     */
    public function initiateStkPush(
        string $phoneNumber,
        int $amountKesCents,
        string $transactionId,
        string $callbackUrl
    ): object;
}