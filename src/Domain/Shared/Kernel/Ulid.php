<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\Kernel;

/**
 * Ulid — Immutable ULID (Universally Unique Lexicographically Sortable Identifier)
 * 128-bit, time-ordered, URL-safe, binary-optimized
 */
abstract class Ulid
{
    private function __construct(private readonly string $value) {}

    public static function generate(): self
    {
        return new static(self::generateBinary());
    }

    public static function fromString(string $value): self
    {
        if (!preg_match('/^[0123456789ABCDEFGHJKMNPQRSTVWXYZabcdefghjkmnpqrstvwxyz]{26}$/i', $value)) {
            throw new \InvalidArgumentException('Invalid ULID format');
        }
        return new static($value);
    }

    public static function fromBytes(string $bytes): self
    {
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('ULID binary must be 16 bytes');
        }
        return new static(self::encode($bytes));
    }

    public function toBytes(): string
    {
        return self::decode($this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    // --- PRIVATE HELPERS ---

    private static function generateBinary(): string
    {
        // Use random_bytes (cryptographically secure)
        return random_bytes(16);
    }

    private static function encode(string $bytes): string
    {
        $encoding = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        $result = '';
        $value = 0;
        $valueLength = 0;

        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
            $valueLength += 8;

            while ($valueLength >= 5) {
                $result .= $encoding[($value >> ($valueLength - 5)) & 31];
                $valueLength -= 5;
            }
        }

        if ($valueLength > 0) {
            $result .= $encoding[($value << (5 - $valueLength)) & 31];
        }

        return str_pad($result, 26, '0', STR_PAD_LEFT);
    }

    private static function decode(string $ulid): string
    {
        $decoding = array_flip(str_split('0123456789ABCDEFGHJKMNPQRSTVWXYZ'));
        $bytes = '';

        foreach (str_split($ulid) as $char) {
            if (!isset($decoding[$char])) {
                throw new \InvalidArgumentException('Invalid ULID character: ' . $char);
            }
            $bytes .= chr($decoding[$char]);
        }

        return substr($bytes . str_repeat("\0", 16), 0, 16);
    }
}