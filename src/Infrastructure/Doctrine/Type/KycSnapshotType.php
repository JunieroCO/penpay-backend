<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Doctrine\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;
use PenPay\Domain\User\ValueObject\KycSnapshot;

final class KycSnapshotType extends JsonType
{
    public const NAME = 'kyc_snapshot';

    public function getName(): string { return self::NAME; }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) return null;
        if (!$value instanceof KycSnapshot) {
            throw new \InvalidArgumentException('Expected KycSnapshot');
        }
        return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?KycSnapshot
    {
        if ($value === null) return null;
        $data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return KycSnapshot::fromDerivResponse($data);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}