<?php
declare(strict_types=1);

namespace PenPay\Application\Auth\Handler;

use PenPay\Application\Auth\Contract\AuthServiceInterface;
use PenPay\Application\Auth\Contract\LogoutHandlerInterface;
use PenPay\Application\Auth\DTO\LogoutRequest;
use PenPay\Infrastructure\Audit\AuditLoggerInterface;
use Throwable;

final readonly class LogoutHandler implements LogoutHandlerInterface
{
    public function __construct(
        private AuthServiceInterface $authService,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function handle(LogoutRequest $request): void
    {
        $startTime = microtime(true);
        $ipAddress = $this->getClientIp();

        try {
            $this->authService->logoutByRefreshToken(
                refreshToken: $request->refreshToken,
                deviceId:     $request->deviceId,
                userAgent:    $request->userAgent,
            );

            $this->auditSuccess($request, $ipAddress, $startTime);

        } catch (Throwable $e) {
            // Even on failure (e.g. token already revoked), we still audit the attempt
            $this->auditFailure($request, $e, $ipAddress, $startTime);
            throw $e; // Let global handler return 401/400 as needed
        }
    }

    private function auditSuccess(LogoutRequest $request, ?string $ip, float $startTime): void
    {
        $this->auditLogger?->info('auth.logout.success', [
            'event'        => 'logout_success',
            'device_id'    => $request->deviceId,
            'user_agent'   => $request->userAgent,
            'ip_address'   => $ip,
            'revoked_all'  => $request->revokeAllDevices ?? false,
            'duration_ms'  => $this->duration($startTime),
            'timestamp'    => date('c'),
        ]);
    }

    private function auditFailure(LogoutRequest $request, Throwable $e, ?string $ip, float $startTime): void
    {
        $this->auditLogger?->warning('auth.logout.failed', [
            'event'        => 'logout_failed',
            'device_id'    => $request->deviceId,
            'user_agent'   => $request->userAgent,
            'ip_address'   => $ip,
            'error'        => $e->getMessage(),
            'duration_ms'  => $this->duration($startTime),
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

    private function duration(float $startTime): float
    {
        return round((microtime(true) - $startTime) * 1000, 2);
    }
}
