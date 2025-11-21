<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use PenPay\Domain\Shared\Kernel\UserId;

final class UserIdType extends StringType
{
    public const NAME = 'user_id';

    public function convertToPHPValue($value, AbstractPlatform $platform): ?UserId
    {
        return $value === null ? null : UserId::fromString($value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) return null;
        if (!$value instanceof UserId) {
            throw new \InvalidArgumentException('Expected UserId, got ' . get_debug_type($value));
        }
        return (string) $value;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}