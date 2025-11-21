<?php
declare(strict_types=1);

namespace PenPay\Domain\Auth\Repository;

use PenPay\Domain\Auth\Entity\RefreshToken;
use PenPay\Domain\Auth\ValueObject\RefreshTokenId;
use PenPay\Domain\Shared\Kernel\UserId;

interface RefreshTokenRepositoryInterface
{
    public function save(RefreshToken $token): void;

    public function findByHash(string $tokenHash): ?RefreshToken;

    /** @return RefreshToken[] */
    public function findActiveByUserAndDevice(UserId $userId, string $deviceFingerprint): array;

    public function revoke(RefreshToken $token): void;

    public function revokeFamily(string $familyId): void;

    public function revokeAllForUser(UserId $userId): void;

    public function findById(RefreshTokenId $id): ?RefreshToken;
}