<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\Entity;

enum LedgerSide: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';
}