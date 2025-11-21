<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use PenPay\Domain\User\ValueObject\DerivLoginId;

final class DerivLoginIdType extends StringType
{
    public const NAME = 'deriv_login_id';

    public function convertToPHPValue($value, AbstractPlatform $platform): ?DerivLoginId
    {
        return $value === null ? null : new DerivLoginId($value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) return null;
        if (!$value instanceof DerivLoginId) {
            throw new \InvalidArgumentException('Expected DerivLoginId, got ' . get_debug_type($value));
        }
        return (string) $value;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}