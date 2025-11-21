<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use PenPay\Domain\User\ValueObject\Email;

final class EmailType extends StringType
{
    public const NAME = 'user_email';

    public function convertToPHPValue($value, AbstractPlatform $platform): ?Email
    {
        return $value === null ? null : new Email($value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) return null;
        if (!$value instanceof Email) {
            throw new \InvalidArgumentException('Expected Email, got ' . get_debug_type($value));
        }
        return (string) $value;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}