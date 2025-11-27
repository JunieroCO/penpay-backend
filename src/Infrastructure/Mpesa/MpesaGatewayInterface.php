<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Mpesa;

interface MpesaGatewayInterface
{
    public function b2c(string $phoneNumber, int $amountKesCents, string $reference): MpesaB2CResult;
}