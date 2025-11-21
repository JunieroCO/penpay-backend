<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Doctrine\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use PenPay\Domain\User\ValueObject\Email;

final class EmailType extends Type
{
    public const NAME = 'email';

    public function getName(): string { return self::NAME; }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // DBAL 3 deprecates getVarcharTypeDeclarationSQL — use getStringTypeDeclarationSQL
        return $platform->getStringTypeDeclarationSQL(array_merge($column, ['length' => 255]));
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        return $value instanceof Email ? (string) $value : null;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?Email
    {
        if ($value === null) return null;
        return Email::fromString($value);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}