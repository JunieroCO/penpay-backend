<?php
declare(strict_types=1);

namespace PenPay\Application\Auth\DTO;

use PenPay\Domain\Shared\ValueObject\PositiveInteger;
use InvalidArgumentException;

final readonly class AuthResponse
{
    private const DEFAULT_ACCESS_TTL     = 900;      // 15 minutes
    private const DEFAULT_REFRESH_TTL    = 2592000;  // 30 days

    private function __construct(
        public string          $accessToken,
        public PositiveInteger $accessTokenExpiresIn,
        public string          $refreshToken,
        public PositiveInteger $refreshTokenExpiresIn,
        public string          $tokenType = 'Bearer',
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function create(
        string $accessToken,
        string $refreshToken,
        ?int   $accessTokenExpiresIn = null,
        ?int   $refreshTokenExpiresIn = null,
    ): self {
        if ($accessToken === '' || $refreshToken === '') {
            throw new InvalidArgumentException('Tokens cannot be empty');
        }

        return new self(
            accessToken:            $accessToken,
            accessTokenExpiresIn:   PositiveInteger::create($accessTokenExpiresIn ?? self::DEFAULT_ACCESS_TTL),
            refreshToken:           $refreshToken,
            refreshTokenExpiresIn:  PositiveInteger::create($refreshTokenExpiresIn ?? self::DEFAULT_REFRESH_TTL),
        );
    }

    /**
     * Standard OAuth2 / OpenID Connect compliant array
     */
    public function toArray(): array
    {
        return [
            'access_token'        => $this->accessToken,
            'token_type'          => $this->tokenType,
            'expires_in'          => $this->accessTokenExpiresIn->value,
            'refresh_token'       => $this->refreshToken,
            'refresh_expires_in'  => $this->refreshTokenExpiresIn->value,
            'issued_at'           => time(),
        ];
    }

    /**
     * Only for testing / deserialization — never used in production flow
     */
    public static function fromArrayForTesting(array $data): self
    {
        return new self(
            accessToken:           $data['access_token']           ?? throw new InvalidArgumentException('access_token missing'),
            accessTokenExpiresIn:  PositiveInteger::create((int)($data['expires_in'] ?? self::DEFAULT_ACCESS_TTL)),
            refreshToken:          $data['refresh_token']          ?? throw new InvalidArgumentException('refresh_token missing'),
            refreshTokenExpiresIn: PositiveInteger::create((int)($data['refresh_expires_in'] ?? self::DEFAULT_REFRESH_TTL)),
        );
    }
}
