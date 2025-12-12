<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Deriv;

use PenPay\Domain\Payments\Entity\DerivResult;

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
    public function paymentAgentDeposit(
        string $loginId,
        float $amountUsd,
        string $paymentAgentToken,
        string $reference,
        array $metadata = []
    ): DerivResult;

    public function paymentAgentWithdraw(
        string $loginId,
        float $amountUsd,
        string $verificationCode,
        string $reference
    ): DerivResult;
}