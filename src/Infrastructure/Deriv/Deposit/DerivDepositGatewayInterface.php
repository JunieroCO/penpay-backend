<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Deriv\Deposit;

use React\Promise\PromiseInterface;

interface DerivDepositGatewayInterface
{
    public function deposit(
        string $loginId,
        float $amountUsd,
        string $paymentAgentToken,
        string $reference,
        array $metadata = []
    ): PromiseInterface;
}