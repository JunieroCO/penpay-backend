<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;
use PenPay\Domain\User\ValueObject\KycSnapshot;

final class KycSnapshotType extends JsonType
{
    public const NAME = 'kyc_snapshot';

    public function convertToPHPValue($value, AbstractPlatform $platform): ?KycSnapshot
    {
        if ($value === null) return null;
        $data = parent::convertToPHPValue($value, $platform);
        return KycSnapshot::fromArray($data);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) return null;
        if (!$value instanceof KycSnapshot) {
            throw new \InvalidArgumentException('Expected KycSnapshot');
        }
        return parent::convertToDatabaseValue($value->toArray(), $platform);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}