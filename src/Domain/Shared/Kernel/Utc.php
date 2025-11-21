<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\Kernel;

use DateTimeZone;
use DateTimeImmutable;

final class Utc
{
    public const  TIMEZONE = 'UTC';

    private function __construct() {}

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::timezone());
    }
}