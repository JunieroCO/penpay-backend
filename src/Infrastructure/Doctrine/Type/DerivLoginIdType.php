<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Doctrine\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use PenPay\Domain\User\ValueObject\DerivLoginId;

final class DerivLoginIdType extends Type
{
    public const NAME = 'deriv_login_id';

    public function getName(): string { return self::NAME; }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?int
    {
        return $value instanceof DerivLoginId ? $value->value() : null;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?DerivLoginId
    {
        if ($value === null) return null;
        return DerivLoginId::fromInt((int) $value);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}