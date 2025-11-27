<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Secret;

use PenPay\Domain\Payments\ValueObject\OneTimeVerificationCode;
use RuntimeException;

final class EncryptedOneTimeSecret
{
    private const ENCRYPTION_KEY = 'def00000...'; // 32-byte key from env

    public static function encrypt(OneTimeVerificationCode $code): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt(
            $code->toString(),
            'aes-256-cbc',
            self::ENCRYPTION_KEY,
            0,
            $iv
        );
        if ($encrypted === false) {
            throw new RuntimeException('Failed to encrypt verification code');
        }
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt(string $payload): OneTimeVerificationCode
    {
        $data = base64_decode($payload, true);
        if ($data === false || strlen($data) < 16) {
            throw new RuntimeException('Invalid encrypted payload');
        }
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $decrypted = openssl_decrypt(
            $encrypted,
            'aes-256-cbc',
            self::ENCRYPTION_KEY,
            0,
            $iv
        );
        if ($decrypted === false) {
            throw new RuntimeException('Failed to decrypt verification code');
        }
        return OneTimeVerificationCode::fromString($decrypted);
    }
}