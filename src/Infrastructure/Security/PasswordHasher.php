<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Security;

use PenPay\Domain\User\ValueObject\PasswordHash;
use RuntimeException;

final class PasswordHasher
{
    private const ARGON2ID_OPTIONS = [
        'memory_cost' => 65536,  // 64 MiB
        'time_cost'   => 4,
        'threads'     => 1,
    ];

    public function hash(string $plain): PasswordHash
    {
        $hash = password_hash($plain, PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS);

        if ($hash === false) {
            throw new RuntimeException('Argon2id hashing failed. Server misconfigured.');
        }

        return PasswordHash::fromHash($hash);
    }
}