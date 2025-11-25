<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\ValueObject;

enum TransactionType: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
}