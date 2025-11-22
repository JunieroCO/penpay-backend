<?php
declare(strict_types=1);

namespace PenPay\Tests\Presentation\Http\Controllers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use PenPay\Presentation\Http\Controllers\AuthController;
use PenPay\Application\Auth\Contract\LoginHandlerInterface;
use PenPay\Application\Auth\Contract\RefreshTokenHandlerInterface;
use PenPay\Application\Auth\Contract\LogoutHandlerInterface;
use PenPay\Application\Auth\DTO\AuthResponse;

final class AuthControllerTest extends TestCase
{
    private LoginHandlerInterface&MockObject $loginHandler;
    private RefreshTokenHandlerInterface&MockObject $refreshHandler;
    private LogoutHandlerInterface&MockObject $logoutHandler;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->loginHandler = $this->createMock(LoginHandlerInterface::class);
        $this->refreshHandler = $this->createMock(RefreshTokenHandlerInterface::class);
        $this->logoutHandler = $this->createMock(LogoutHandlerInterface::class);

        $this->controller = new AuthController(
            $this->loginHandler,
            $this->refreshHandler,
            $this->logoutHandler
        );
    }

    /* -----------------------------------------------------------
        LOGIN TESTS 
    ----------------------------------------------------------- */

    public function testLoginSuccess(): void
    {
        $expected = AuthResponse::create(
            accessToken: 'access123',
            refreshToken: 'refresh123',
            accessTokenExpiresIn: 900,
            refreshTokenExpiresIn: 2592000
        );

        $this->loginHandler
            ->expects($this->once())
            ->method('handle')
            ->willReturn($expected);

        $req = new ServerRequest(
            'POST',
            '/api/v1/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode([
                'email' => 'user@example.com',
                'password' => 'password123',
                'device_id' => 'dev123'
            ])
        );

        /** @var \Psr\Http\Message\ServerRequestInterface $req */
        $res = $this->controller->login($req);

        $this->assertSame(200, $res->getStatusCode());
        $payload = json_decode((string)$res->getBody(), true);

        $this->assertSame('access123', $payload['access_token']);
        $this->assertSame('refresh123', $payload['refresh_token']);
    }

    public function testLoginInvalidPayloadReturns400(): void
    {
        $req = new ServerRequest(
            'POST',
            '/api/v1/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['email' => 'bad'])
        );

        /** @var \Psr\Http\Message\ServerRequestInterface $req */
        $res = $this->controller->login($req);

        $this->assertSame(400, $res->getStatusCode());
    }

    public function testLoginHandlerThrowsExceptionReturns401(): void
    {
        // RuntimeException is caught and returns 401 (for business logic errors like "Invalid credentials")
        $this->loginHandler
            ->expects($this->once())
            ->method('handle')
            ->willThrowException(new \RuntimeException('Invalid credentials'));

        $req = new ServerRequest(
            'POST',
            '/api/v1/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode([
                'email' => 'user@example.com',
                'password' => 'pass'
            ])
        );

        /** @var \Psr\Http\Message\ServerRequestInterface $req */
        $res = $this->controller->login($req);

        $this->assertSame(401, $res->getStatusCode());
    }

    public function testLoginHandlerThrowsGenericExceptionReturns500(): void
    {
        // Generic exceptions (not RuntimeException) return 500
        $this->loginHandler
            ->expects($this->once())
            ->method('handle')
            ->willThrowException(new \Exception('DB error'));

        $req = new ServerRequest(
            'POST',
            '/api/v1/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode([
                'email' => 'user@example.com',
                'password' => 'pass'
            ])
        );

        /** @var \Psr\Http\Message\ServerRequestInterface $req */
        $res = $this->controller->login($req);

        $this->assertSame(500, $res->getStatusCode());
    }

    /* -----------------------------------------------------------
        REFRESH TESTS
    ----------------------------------------------------------- */

    public function testRefreshSuccess(): void
    {
        $expected = AuthResponse::create(
            accessToken: 'newAccess123',
            refreshToken: 'newRefresh123',
            accessTokenExpiresIn: 900,
            refreshTokenExpiresIn: 2592000
        );

        $this->refreshHandler
            ->expects($this->once())
            ->method('handle')
            ->willReturn($expected);

        $req = new ServerRequest(
            'POST',
            '/api/v1/auth/refresh',
            ['Content-Type' => 'application/json'],
            json_encode(['refresh_token' => 'oldtoken123'])
        );

        /** @var \Psr\Http\Message\ServerRequestInterface $req */
        $res = $this->controller->refresh($req);

        $payload = json_decode((string)$res->getBody(), true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('newAccess123', $payload['access_token']);
    }

    public function testRefreshInvalidPayloadReturns400(): void
    {
        $req = new ServerRequest(
            'POST',
            '/api/v1/auth/refresh',
            ['Content-Type' => 'application/json'],
            json_encode([])
        );

        /** @var \Psr\Http\Message\ServerRequestInterface $req */
        $res = $this->controller->refresh($req);

        $this->assertSame(400, $res->getStatusCode());
    }

    public function testRefreshHandlerThrowsReturns401(): void
    {
        $this->refreshHandler
            ->expects($this->once())
            ->method('handle')
            ->willThrowException(new \RuntimeException('Invalid or expired refresh token'));

        $req = new ServerRequest(
            'POST',
            '/api/v1/auth/refresh',
            ['Content-Type' => 'application/json'],
            json_encode(['refresh_token' => 'bad'])
        );

        /** @var \Psr\Http\Message\ServerRequestInterface $req */
        $res = $this->controller->refresh($req);

        $this->assertSame(401, $res->getStatusCode());
    }

    /* -----------------------------------------------------------
        LOGOUT TESTS
    ----------------------------------------------------------- */

    public function testLogoutSuccessReturns204(): void
    {
        $this->logoutHandler
            ->expects($this->once())
            ->method('handle');

        $req = new ServerRequest(
            'POST',
            '/api/v1/auth/logout',
            ['Content-Type' => 'application/json'],
            json_encode(['refresh_token' => 'tok'])
        );

        /** @var \Psr\Http\Message\ServerRequestInterface $req */
        $res = $this->controller->logout($req);

        $this->assertSame(204, $res->getStatusCode());
    }

    public function testLogoutInvalidPayloadReturns400(): void
    {
        $req = new ServerRequest(
            'POST',
            '/api/v1/auth/logout',
            ['Content-Type' => 'application/json'],
            json_encode([])
        );

        /** @var \Psr\Http\Message\ServerRequestInterface $req */
        $res = $this->controller->logout($req);

        $this->assertSame(400, $res->getStatusCode());
    }
}