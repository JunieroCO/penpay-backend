<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use PenPay\Domain\User\ValueObject\PhoneNumber;

final class PhoneNumberType extends StringType
{
    public const NAME = 'phone_number_e164';

    public function convertToPHPValue($value, AbstractPlatform $platform): ?PhoneNumber
    {
        return $value === null ? null : new PhoneNumber($value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) return null;
        if (!$value instanceof PhoneNumber) {
            throw new \InvalidArgumentException('Expected PhoneNumber, got ' . get_debug_type($value));
        }
        return $value->toE164();
    }

    public function getName(): string
    {
        return self::NAME;
    }
}