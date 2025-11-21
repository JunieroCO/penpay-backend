<?php
declare(strict_types=1);

use Doctrine\DBAL\Types\Type;
use PenPay\Infrastructure\Doctrine\Types\UserIdType;
use PenPay\Infrastructure\Doctrine\Types\EmailType;
use PenPay\Infrastructure\Doctrine\Types\PhoneNumberType;
use PenPay\Infrastructure\Doctrine\Types\DerivLoginIdType;
use PenPay\Infrastructure\Doctrine\Types\PasswordHashType;
use PenPay\Infrastructure\Doctrine\Types\KycSnapshotType;

return static function (): void {
    if (!Type::hasType(UserIdType::NAME)) {
        Type::addType(UserIdType::NAME, UserIdType::class);
    }

    if (!Type::hasType(EmailType::NAME)) {
        Type::addType(EmailType::NAME, EmailType::class);
    }

    if (!Type::hasType(PhoneNumberType::NAME)) {
        Type::addType(PhoneNumberType::NAME, PhoneNumberType::class);
    }

    if (!Type::hasType(DerivLoginIdType::NAME)) {
        Type::addType(DerivLoginIdType::NAME, DerivLoginIdType::class);
    }

    if (!Type::hasType(PasswordHashType::NAME)) {
        Type::addType(PasswordHashType::NAME, PasswordHashType::class);
    }

    if (!Type::hasType(KycSnapshotType::NAME)) {
        Type::addType(KycSnapshotType::NAME, KycSnapshotType::class);
    }
};