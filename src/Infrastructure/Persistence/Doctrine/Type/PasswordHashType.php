<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use PenPay\Domain\User\ValueObject\PasswordHash;

final class PasswordHashType extends StringType
{
    public const NAME = 'password_hash';

    public function convertToPHPValue($value, AbstractPlatform $platform): ?PasswordHash
    {
        return $value === null ? null : new PasswordHash($value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) return null;
        if (!$value instanceof PasswordHash) {
            throw new \InvalidArgumentException('Expected PasswordHash, got ' . get_debug_type($value));
        }
        return (string) $value;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}