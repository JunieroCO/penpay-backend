<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Deriv;

use PenPay\Domain\Payments\Entity\DerivTransferResult;

/**
 * Deriv Payment Agent Gateway — The Final Truth
 *
 * This is the single point of contact with Deriv's Payment Agent API.
 * It speaks only in domain language.
 * It never lies.
 * It never returns arrays.
 */
interface DerivGatewayInterface
{
    /**
     * Deposit USD into a user's Deriv account via Payment Agent
     *
     * @param string $loginId            Deriv account login (e.g. CR123456)
     * @param float  $amountUsd          Exact USD amount (e.g. 10.50)
     * @param string $paymentAgentToken  Your Payment Agent authentication token
     * @param string $reference          Your internal transaction reference
     * @param array  $metadata           Optional: mpesa_receipt, phone, etc.
     *
     * @return DerivTransferResult       Always returns a result object — success or failure
     *
     * @throws \RuntimeException         On network timeout, auth failure, etc.
     * @throws \InvalidArgumentException On invalid input
     */
    public function paymentAgentDeposit(
        string $loginId,
        float $amountUsd,
        string $paymentAgentToken,
        string $reference,
        array $metadata = []
    ): DerivTransferResult;

    public function paymentAgentWithdraw(
        string $loginId,
        float $amountUsd,
        string $verificationCode,
        string $reference
    ): DerivTransferResult;
}