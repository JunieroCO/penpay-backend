<?php
declare(strict_types=1);

namespace PenPay\Application\Auth\Handler;

use PenPay\Application\Auth\Contract\AuthServiceInterface;
use PenPay\Application\Auth\Contract\RefreshTokenHandlerInterface;
use PenPay\Application\Auth\DTO\RefreshTokenRequest;
use PenPay\Application\Auth\DTO\AuthResponse;
use PenPay\Infrastructure\Audit\AuditLoggerInterface;
use Throwable;

final readonly class RefreshTokenHandler implements RefreshTokenHandlerInterface
{
    private const ACCESS_TOKEN_TTL  = 900;
    private const REFRESH_TOKEN_TTL = 2_592_000;

    public function __construct(
        private AuthServiceInterface $authService,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function handle(RefreshTokenRequest $request): AuthResponse
    {
        $startTime = microtime(true);

        try {
            $result = $this->authService->refresh(
                refreshToken: $request->refreshToken,
                deviceId:     $request->deviceId ?? 'unknown-device',
                userAgent:    $request->userAgent ?? 'unknown-agent',
            );

            $this->auditSuccess($result['user_id'] ?? null, $request, $startTime);

            return AuthResponse::create(
                accessToken:           $result['access_token'],
                refreshToken:          $result['refresh_token'],
                accessTokenExpiresIn:  self::ACCESS_TOKEN_TTL,
                refreshTokenExpiresIn: self::REFRESH_TOKEN_TTL,
            );

        } catch (Throwable $e) {
            $this->auditFailure($request, $e, $startTime);
            throw $e;
        }
    }

    private function auditSuccess(?string $userId, RefreshTokenRequest $request, float $startTime): void
    {
        $this->auditLogger?->info('auth.refresh.success', [
            'event'       => 'token_refresh_success',
            'user_id'     => $userId,
            'device_id'   => $request->deviceId,
            'user_agent'  => $request->userAgent,
            'ip_address'  => $this->getClientIp(),
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
        ]);
    }

    private function auditFailure(RefreshTokenRequest $request, Throwable $e, float $startTime): void
    {
        $this->auditLogger?->warning('auth.refresh.failed', [
            'event'       => 'token_refresh_failed',
            'device_id'   => $request->deviceId,
            'user_agent'  => $request->userAgent,
            'ip_address'  => $this->getClientIp(),
            'error'       => $e->getMessage(),
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
        ]);
    }

    private function getClientIp(): ?string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? null;
    }
}