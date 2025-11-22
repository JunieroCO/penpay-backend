<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use DateTimeImmutable;
use RuntimeException;

final class JWTHandler
{
    private const ALGO = 'RS256';

    private readonly \OpenSSLAsymmetricKey $privateKey;
    private readonly \OpenSSLAsymmetricKey $publicKey;

    public function __construct(
        string $privateKeyPath,
        string $publicKeyPath,
        private readonly string $issuer,
        private readonly int $accessTokenTtl = 900,
    ) {
        // No need to set leeway in modern versions - it's handled automatically

        $privatePem = file_get_contents($privateKeyPath);
        $publicPem  = file_get_contents($publicKeyPath);

        if ($privatePem === false || $publicPem === false) {
            throw new RuntimeException('Failed to read JWT key files');
        }

        $this->privateKey = openssl_pkey_get_private($privatePem);
        $this->publicKey  = openssl_pkey_get_public($publicPem);

        if ($this->privateKey === false || $this->publicKey === false) {
            throw new RuntimeException('Invalid JWT key: ' . openssl_error_string());
        }
    }

    public function issueAccessToken(
        string $userId,
        string $deviceFingerprint,
        string $jti,
        array $extraClaims = []
    ): string {
        $now = new DateTimeImmutable();
        $payload = array_merge([
            'iss' => $this->issuer,
            'sub' => $userId,
            'iat' => $now->getTimestamp(),
            'exp' => $now->modify("+{$this->accessTokenTtl} seconds")->getTimestamp(),
            'jti' => $jti,
            'device' => $deviceFingerprint,
        ], $extraClaims);

        return JWT::encode($payload, $this->privateKey, self::ALGO);
    }

    public function validateAccessToken(string $token): object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->publicKey, self::ALGO));
            if ($decoded->iss !== $this->issuer) {
                throw new RuntimeException('Invalid issuer');
            }
            return $decoded;
        } catch (\Exception $e) {
            throw new RuntimeException('Invalid token: ' . $e->getMessage());
        }
    }
}