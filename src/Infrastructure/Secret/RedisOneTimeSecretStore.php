<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Secret;

use Redis;
use RedisException;
use RuntimeException;
use SensitiveParameter;

final class RedisOneTimeSecretStore implements OneTimeSecretStoreInterface
{
    private const LUA_SCRIPT = <<<'LUA'
        local value = redis.call('GET', KEYS[1])
        if value then
            redis.call('DEL', KEYS[1])
            return value
        end
        return false
        LUA;

    private string $scriptSha;

    public function __construct(
        private readonly Redis $redis,
        #[SensitiveParameter] private readonly string $encryptionKey,
        private readonly int $defaultTtlSeconds = 600 // 10 minutes
    ) {
        if (strlen($this->encryptionKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Encryption key must be 32 bytes (SODIUM_CRYPTO_SECRETBOX_KEYBYTES)');
        }

        // Pre-load Lua script SHA for atomic EVALSHA
        $this->scriptSha = $this->redis->script('LOAD', self::LUA_SCRIPT);
    }

    public function store(string $key, string $value, ?int $ttlSeconds = null): void
    {
        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds;

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_aes256gcm_encrypt(
            $value,
            '', // no AAD
            $nonce,
            $this->encryptionKey
        );

        $payload = $nonce . $ciphertext;

        try {
            $this->redis->setEx($key, $ttl, $payload);
        } catch (RedisException $e) {
            throw new RuntimeException('Failed to store one-time secret', 0, $e);
        }
    }

    public function getAndDelete(string $key): ?string
    {
        try {
            $raw = $this->redis->evalSha($this->scriptSha, [$key], 1);

            if ($raw === false || $raw === null) {
                return null;
            }

            if (strlen($raw) < SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES) {
                return null; // corrupted
            }

            $nonce = substr($raw, 0, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
            $ciphertext = substr($raw, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);

            $plaintext = sodium_crypto_aead_aes256gcm_decrypt(
                $ciphertext,
                '',
                $nonce,
                $this->encryptionKey
            );

            if ($plaintext === false) {
                return null; // tampering detected
            }

            sodium_memzero($raw);
            return $plaintext;

        } catch (RedisException $e) {
            throw new RuntimeException('Failed to retrieve one-time secret', 0, $e);
        }
    }
}