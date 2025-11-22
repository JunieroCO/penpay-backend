<?php
declare(strict_types=1);

namespace PenPay\Application\Auth\Handler;

use PenPay\Application\Auth\Contract\AuthServiceInterface;
use PenPay\Application\Auth\Contract\LoginHandlerInterface;
use PenPay\Application\Auth\DTO\LoginRequest;
use PenPay\Application\Auth\DTO\AuthResponse;
use PenPay\Infrastructure\Audit\AuditLoggerInterface;
use PenPay\Domain\Shared\Kernel\UserId;
use Throwable;

final readonly class LoginHandler implements LoginHandlerInterface
{
    private const ACCESS_TOKEN_TTL  = 900;     // 15 minutes
    private const REFRESH_TOKEN_TTL = 2_592_000; // 30 days

    public function __construct(
        private AuthServiceInterface $authService,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function handle(LoginRequest $request): AuthResponse
    {
        $startTime = microtime(true);

        try {
            $result = $this->authService->login(
                email:     (string) $request->email,
                password:  $request->password,
                deviceId:  $request->deviceId ?? 'unknown-device',
                userAgent: $request->userAgent ?? 'unknown-agent',
            );

            $userId = $result['user_id'] ?? null;

            $this->auditSuccess($userId, $request, $startTime);

            return AuthResponse::create(
                accessToken:           $result['access_token'],
                refreshToken:          $result['refresh_token'],
                accessTokenExpiresIn:  self::ACCESS_TOKEN_TTL,
                refreshTokenExpiresIn: self::REFRESH_TOKEN_TTL,
            );

        } catch (Throwable $e) {
            $this->auditFailure($request, $e, $startTime);
            throw $e; // Let global exception handler format it
        }
    }

    private function auditSuccess(?string $userId, LoginRequest $request, float $startTime): void
    {
        $this->auditLogger?->info('auth.login.success', [
            'event'        => 'login_success',
            'user_id'      => $userId,
            'email'        => (string) $request->email,
            'device_id'    => $request->deviceId,
            'user_agent'   => $request->userAgent,
            'ip_address'   => $this->getClientIp(),
            'duration_ms'  => round((microtime(true) - $startTime) * 1000, 2),
            'timestamp'    => date('c'),
        ]);
    }

    private function auditFailure(LoginRequest $request, Throwable $e, float $startTime): void
    {
        $this->auditLogger?->warning('auth.login.failed', [
            'event'        => 'login_failed',
            'email'        => (string) $request->email,
            'device_id'    => $request->deviceId,
            'user_agent'   => $request->userAgent,
            'ip_address'   => $this->getClientIp(),
            'error'        => $e->getMessage(),
            'duration_ms'  => round((microtime(true) - $startTime) * 1000, 2),
            'timestamp'    => date('c'),
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
