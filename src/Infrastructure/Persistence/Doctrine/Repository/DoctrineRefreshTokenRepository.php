<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use PenPay\Domain\Auth\Entity\RefreshToken;
use PenPay\Domain\Auth\Repository\RefreshTokenRepositoryInterface;
use PenPay\Domain\Auth\ValueObject\RefreshTokenId;
use PenPay\Domain\Shared\Kernel\UserId;

final class DoctrineRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function save(RefreshToken $token): void
    {
        $data = [
            'id'              => $token->id()->toBytes(),
            'user_id'         => $token->userId()->toBytes(),
            'device_fingerprint' => $token->deviceFingerprint()->toString(),
            'token_hash'      => $token->tokenHash(),
            'family'          => $token->family()->toString(),
            'expires_at'      => $token->expiresAt(),
            'created_at'      => $token->createdAt(),
            'last_used_at'    => $token->lastUsedAt(),
            'revoked'         => $token->isRevoked(),
        ];

        $this->connection->insert('refresh_tokens', $data, [
            'id'           => 'binary',
            'user_id'      => 'binary',
            'expires_at'   => Types::DATETIME_IMMUTABLE,
            'created_at'   => Types::DATETIME_IMMUTABLE,
            'last_used_at' => Types::DATETIME_IMMUTABLE,
            'revoked'      => Types::BOOLEAN,
        ]);
    }

    public function findByHash(string $tokenHash): ?RefreshToken
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM refresh_tokens WHERE token_hash = ? AND revoked = 0 AND expires_at > NOW() LIMIT 1',
            [$tokenHash]
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function findById(RefreshTokenId $id): ?RefreshToken
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM refresh_tokens WHERE id = ?',
            [$id->toBytes()],
            ['binary']
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function findActiveByUserAndDevice(UserId $userId, string $deviceFingerprint): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM refresh_tokens 
             WHERE user_id = ? 
               AND device_fingerprint = ? 
               AND revoked = 0 
               AND expires_at > NOW()',
            [$userId->toBytes(), $deviceFingerprint],
            ['binary', 'string']
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    public function revoke(RefreshToken $token): void
    {
        $this->connection->update(
            'refresh_tokens',
            ['revoked' => true],
            ['id' => $token->id()->toBytes()],
            ['binary']
        );
    }

    public function revokeFamily(string $familyId): void
    {
        $this->connection->update(
            'refresh_tokens',
            ['revoked' => true],
            ['family' => $familyId]
        );
    }

    public function revokeAllForUser(UserId $userId): void
    {
        $this->connection->update(
            'refresh_tokens',
            ['revoked' => true],
            ['user_id' => $userId->toBytes()],
            ['binary']
        );
    }

    private function hydrate(array $row): RefreshToken
    {
        return RefreshToken::issue(
            userId: UserId::fromBytes($row['user_id']),
            deviceFingerprint: DeviceFingerprint::fromHash($row['device_fingerprint']),
            rawToken: '', // not needed
            family: TokenFamily::fromString($row['family']),
            expiresAt: new \DateTimeImmutable($row['expires_at']),
        );
        // Note: full rehydration would require factory — but we use issue() + override if needed
    }
}