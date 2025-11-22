<?php
declare(strict_types=1);

namespace PenPay\Domain\Shared\Kernel;

final class Ulid
{
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private function __construct(private readonly string $value) {}

    public static function generate(): self
    {
        return new self(self::encode(random_bytes(16)));
    }

    public static function fromString(string $value): self
    {
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value)) {
            throw new \InvalidArgumentException('Invalid ULID format');
        }
        return new self(strtoupper($value));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function encode(string $bytes): string
    {
        $encoding = self::ENCODING;
        $result = '';
        $value = 0;
        $valueBits = 0;

        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
            $valueBits += 8;
            while ($valueBits >= 5) {
                $result .= $encoding[($value >> ($valueBits - 5)) & 31];
                $valueBits -= 5;
            }
        }

        if ($valueBits > 0) {
            $result .= $encoding[($value << (5 - $valueBits)) & 31];
        }

        return str_pad($result, 26, '0', STR_PAD_LEFT);
    }
}