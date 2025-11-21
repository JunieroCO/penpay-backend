<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Doctrine\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use PenPay\Domain\Shared\Kernel\UserId;
use InvalidArgumentException;

final class UserIdType extends Type
{
    public const NAME = 'user_id';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // UUID v7 — binary(16) is faster + smaller than CHAR(36)
        return $platform->getBinaryTypeDeclarationSQL([
            'length' => 16,
            'fixed'  => true,
        ]);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!$value instanceof UserId) {
            throw new InvalidArgumentException('Expected UserId, got ' . get_debug_type($value));
        }

        // Store as binary(16) — fastest possible
        return $value->toBytes();
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?UserId
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return UserId::fromBytes($value);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}