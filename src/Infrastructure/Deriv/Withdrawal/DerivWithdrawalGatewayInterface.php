<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Deriv\Withdrawal;

use PenPay\Domain\Payments\Entity\DerivWithdrawalResult;
use React\Promise\PromiseInterface;

interface DerivWithdrawalGatewayInterface
{
    /**
     * Withdraw USD from a user's Deriv account via Payment Agent
     *
     * @param string $loginId            Deriv account login (e.g., CR123456)
     * @param float  $amountUsd          Exact USD amount
     * @param string $verificationCode   Code sent to user's email
     * @param string $reference          Internal transaction reference
     *
     * @return PromiseInterface<DerivWithdrawalResult> Async result
     *
     * @throws \InvalidArgumentException
     */
    public function withdraw(
        string $loginId,
        float $amountUsd,
        string $verificationCode,
        string $reference
    ): PromiseInterface;
}