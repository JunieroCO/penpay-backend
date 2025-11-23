<?php
declare(strict_types=1);

namespace PenPay\Domain\Wallet\ValueObject;

enum Currency: string
{
    case USD = 'USD';
    case KES = 'KES';

    public function isUsd(): bool
    {
        return $this === self::USD;
    }

    public function isKes(): bool
    {
        return $this === self::KES;
    }

    public function symbol(): string
    {
        return match ($this) {
            self::USD => '$',
            self::KES => 'KSh',
        };
    }
}