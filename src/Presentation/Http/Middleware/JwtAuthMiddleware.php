<?php
declare(strict_types=1);

namespace PenPay\Presentation\Http\Middleware;

use PenPay\Domain\Auth\ValueObject\DeviceFingerprint;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Infrastructure\Auth\JWTHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * JwtAuthMiddleware — Final Guardian of the Empire
 * - RS256 verification
 * - Device fingerprint binding
 * - JTI not needed (short-lived token + rotation)
 * - Injects authenticated UserId into request
 */
final class JwtAuthMiddleware
{
    public function __construct(private readonly JWTHandler $jwt) {}

    public function __invoke(Request $request, callable $next): Response
    {
        $authHeader = $request->headers->get('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            throw new UnauthorizedHttpException('Bearer', 'Missing or invalid Authorization header');
        }

        $token = substr($authHeader, 7);

        try {
            $claims = $this->jwt->validateAccessToken($token);
        } catch (\Throwable) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid or expired access token');
        }

        // === DEVICE BINDING VALIDATION ===
        $deviceFingerprint = DeviceFingerprint::fromString(
            $request->headers->get('X-Device-Id', 'unknown'),
            $request->headers->get('User-Agent', 'unknown')
        );

        if (!isset($claims->device) || !hash_equals($claims->device, $deviceFingerprint->toString())) {
            throw new UnauthorizedHttpException('Bearer', 'Device binding failed — token not valid for this device');
        }

        // === VALID TOKEN — INJECT AUTHENTICATED USER ===
        $userId = UserId::fromString($claims->sub);

        // Attach to request for controllers
        $request->attributes->set('user_id', $userId);
        $request->attributes->set('jwt_claims', $claims);

        return $next($request);
    }
}