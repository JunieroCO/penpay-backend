<?php
declare(strict_types=1);

namespace Tests\Application\Http\Middleware;

use PHPUnit\Framework\TestCase;
use PenPay\Presentation\Http\Middleware\JwtAuthMiddleware;
use PenPay\Infrastructure\Auth\JWTHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use PenPay\Domain\Shared\Kernel\UserId;

final class JwtAuthMiddlewareTest extends TestCase
{
    private JWTHandler $jwtHandler;
    private JwtAuthMiddleware $middleware;

    private const PRIVATE_KEY = __DIR__ . '/../../keys/private.pem';
    private const PUBLIC_KEY  = __DIR__ . '/../../keys/public.pem';
    private const ISSUER      = 'https://penpay.test';

    protected function setUp(): void
    {
        if (!file_exists(self::PRIVATE_KEY) || !file_exists(self::PUBLIC_KEY)) {
            $this->markTestSkipped('JWT keys not found — run: tests/keys/generate.sh');
        }

        $this->jwtHandler = new JWTHandler(
            self::PRIVATE_KEY,
            self::PUBLIC_KEY,
            self::ISSUER,
            900
        );

        $this->middleware = new JwtAuthMiddleware($this->jwtHandler);
    }

    /** @test */
    public function valid_token_passes_and_sets_user_id(): void
    {
        $userId   = 'user-123456789';
        $deviceId = 'iphone15-pro-max';
        $jti      = bin2hex(random_bytes(16));

        $token = $this->jwtHandler->issueAccessToken($userId, $deviceId, $jti);

        $request = Request::create('/api/wallet', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");
        $request->headers->set('X-Device-Id', $deviceId);
        $request->headers->set('User-Agent', 'PenPay/2.1 (iOS)');

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;

            $userIdAttr = $req->attributes->get('user_id');
            $this->assertInstanceOf(UserId::class, $userIdAttr);
            $this->assertSame('user-123456789', (string) $userIdAttr);

            return new Response('OK', 200);
        };

        $response = ($this->middleware)($request, $next);

        $this->assertTrue($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    /** @test */
    public function missing_token_throws_401(): void
    {
        $request = Request::create('/api/secure');
        $next = fn() => new Response('should not reach');

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Missing or invalid Authorization header');

        ($this->middleware)($request, $next);
    }

    /** @test */
    public function invalid_token_throws_401(): void
    {
        $request = Request::create('/api/secure');
        $request->headers->set('Authorization', 'Bearer this.is.not.a.jwt');

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Invalid or expired access token');

        ($this->middleware)($request, fn() => new Response());
    }

    /** @test */
    public function device_mismatch_throws_401(): void
    {
        $userId   = 'user-999';
        $deviceId = 'correct-device';
        $jti      = 'jti-999';

        $token = $this->jwtHandler->issueAccessToken($userId, $deviceId, $jti);

        $request = Request::create('/api/secure');
        $request->headers->set('Authorization', "Bearer {$token}");
        $request->headers->set('X-Device-Id', 'hacked-device-666');
        $request->headers->set('User-Agent', 'EvilHacker/666');

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Device binding failed');

        ($this->middleware)($request, fn() => new Response());
    }

    /** @test */
    public function wrong_issuer_throws_401(): void
    {
        $evilJwt = new JWTHandler(
            self::PRIVATE_KEY,
            self::PUBLIC_KEY,
            'https://evil-corp.com',
            900
        );

        $token = $evilJwt->issueAccessToken('hacker', 'dev', 'jti-evil');

        $request = Request::create('/api/secure');
        $request->headers->set('Authorization', "Bearer {$token}");
        $request->headers->set('X-Device-Id', 'dev');
        $request->headers->set('User-Agent', 'Test');

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Invalid token');

        ($this->middleware)($request, fn() => new Response());
    }
}