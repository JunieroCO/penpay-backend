<?php
namespace PenPay\Domain\Payments\Factory;

use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Payments\Aggregate\Transaction;  

interface TransactionFactoryInterface
{
    public function createDepositTransaction(
        string $userId, 
        float $amountUsd, 
        LockedRate $lockedRate, 
        ?IdempotencyKey $idempotencyKey = null
    ): Transaction;  
}