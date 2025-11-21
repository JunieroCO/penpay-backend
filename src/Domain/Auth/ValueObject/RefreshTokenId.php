<?php
declare(strict_types=1);

namespace PenPay\Domain\Auth\ValueObject;

use PenPay\Domain\Shared\Kernel\Ulid;

final class RefreshTokenId extends Ulid
{
    public static function generate(): self
    {
        return parent::generate();
    }

    public function toString(): string
    {
        return $this->__toString();
    }
}