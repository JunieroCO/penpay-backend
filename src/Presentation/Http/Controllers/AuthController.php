<?php
declare(strict_types=1);

namespace PenPay\Presentation\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Nyholm\Psr7\Response;

use PenPay\Application\Auth\Contract\LoginHandlerInterface;
use PenPay\Application\Auth\Contract\RefreshTokenHandlerInterface;
use PenPay\Application\Auth\Contract\LogoutHandlerInterface;
use PenPay\Application\Auth\DTO\LoginRequest;
use PenPay\Application\Auth\DTO\RefreshTokenRequest;
use PenPay\Application\Auth\DTO\LogoutRequest;
use PenPay\Infrastructure\Http\UserAgentParserInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use JsonException;

final readonly class AuthController
{
    public function __construct(
        private LoginHandlerInterface         $loginHandler,
        private RefreshTokenHandlerInterface  $refreshHandler,
        private LogoutHandlerInterface        $logoutHandler,
        private ?UserAgentParserInterface $userAgentParser = null,
    ) {}

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->parseJsonBody($request);
        $userAgent = $this->getUserAgent($request);

        try {
            $dto = LoginRequest::fromArray($payload, $userAgent);
            $response = $this->loginHandler->handle($dto);

            return $this->json(200, $response->toArray());
        } catch (InvalidArgumentException $e) {
            return $this->json(400, ['error' => 'validation_failed', 'message' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            return $this->json(401, ['error' => 'unauthorized', 'message' => 'Invalid credentials']);
        } catch (Throwable) {
            return $this->json(500, ['error' => 'internal_error']);
        }
    }

    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->parseJsonBody($request);
        $userAgent = $this->getUserAgent($request);

        try {
            $dto = RefreshTokenRequest::fromArray($payload, $userAgent);
            $response = $this->refreshHandler->handle($dto);

            return $this->json(200, $response->toArray());
        } catch (InvalidArgumentException $e) {
            return $this->json(400, ['error' => 'validation_failed', 'message' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            return $this->json(401, ['error' => 'invalid_token', 'message' => 'Invalid or expired refresh token']);
        } catch (Throwable) {
            return $this->json(500, ['error' => 'internal_error']);
        }
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->parseJsonBody($request);
        $userAgent = $this->getUserAgent($request);

        try {
            $dto = LogoutRequest::fromArray($payload, $userAgent);
            $this->logoutHandler->handle($dto);

            return new Response(204);
        } catch (InvalidArgumentException $e) {
            return $this->json(400, ['error' => 'validation_failed', 'message' => $e->getMessage()]);
        } catch (Throwable) {
            return new Response(204);
        }
    }

    private function parseJsonBody(ServerRequestInterface $request): array
    {
        $body = (string) $request->getBody();

        if ($body === '') {
            return [];
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (JsonException) {
            return [];
        }
    }

    private function getUserAgent(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('User-Agent');

        if ($header === '' || $this->userAgentParser === null) {
            return $header ?: null;
        }

        return $this->userAgentParser->parse($header)->toString();
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        return new Response(
            status: $status,
            headers: [
                'Content-Type'  => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma'        => 'no-cache',
            ],
            body: $body
        );
    }
}