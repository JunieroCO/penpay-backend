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
     * Create from an already-computed Argon2id hash (Infrastructure → Domain)
     */
    public static function fromHash(string $hash): self
    {
        return new self($hash);
    }

    /**
     * Verify a plain password against the stored hash
     */
    public function verify(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->hash);
    }

    /**
     * Returns the hash for persistence
     */
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