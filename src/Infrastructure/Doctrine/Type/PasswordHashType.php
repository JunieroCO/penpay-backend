<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Doctrine\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use PenPay\Domain\User\ValueObject\PasswordHash;

final class PasswordHashType extends Type
{
    public const NAME = 'password_hash';

    public function getName(): string { return self::NAME; }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(array_merge($column, ['length' => 255]));
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        return $value instanceof PasswordHash ? (string) $value : null;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?PasswordHash
    {
        if ($value === null) return null;
        return PasswordHash::fromHash($value);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}