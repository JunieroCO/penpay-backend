<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Auth;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

final class JWTHandler
{
    private const ALGO = 'RS256';

    public function __construct(
        private readonly string $privateKey,
        private readonly string $publicKey,
        private readonly string $issuer,
        private readonly int $accessTokenTtl = 900, // 15 min
    ) {
        JWT::$leeway = 60; // tolerance for clock drift
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