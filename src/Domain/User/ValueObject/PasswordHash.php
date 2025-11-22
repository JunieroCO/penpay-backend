<?php
declare(strict_types=1);

namespace PenPay\Domain\User\ValueObject;

use InvalidArgumentException;

final readonly class PasswordHash
{
    private string $hash;

    private function __construct(string $hash)
    {
        if ($hash === '' || !password_get_info($hash)['algo']) {
            throw new InvalidArgumentException('Invalid password hash format');
        }
        $this->hash = $hash;
    }

    /**
     * Create from an already-computed Argon2id hash (used in infrastructure → domain)
     */
    public static function fromHash(string $hash): self
    {
        return new self($hash);
    }

    /**
     * Hash a plain password (test + registration use case)
     */
    public static function hash(string $plainPassword): self
    {
        $hashed = password_hash(
            $plainPassword,
            PASSWORD_ARGON2ID,
            ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3]
        );

        if ($hashed === false) {
            throw new InvalidArgumentException('Failed to hash password');
        }

        return new self($hashed);
    }

    public function verify(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->hash);
    }

    public function toString(): string
    {
        return $this->hash;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->hash, $other->hash);
    }

    public function __toString(): string
    {
        return $this->hash;
    }
}