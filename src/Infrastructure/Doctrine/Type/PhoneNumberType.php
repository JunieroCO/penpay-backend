<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Doctrine\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use PenPay\Domain\User\ValueObject\PhoneNumber;

final class PhoneNumberType extends Type
{
    public const NAME = 'phone_number';

    public function getName(): string { return self::NAME; }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(array_merge($column, ['length' => 20]));
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        return $value instanceof PhoneNumber ? $value->toE164() : null;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?PhoneNumber
    {
        if ($value === null) return null;
        return PhoneNumber::fromE164($value);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}