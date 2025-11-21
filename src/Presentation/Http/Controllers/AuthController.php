<?php
declare(strict_types=1);

namespace PenPay\Presentation\Http\Controller;

use PenPay\Application\Auth\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthController
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->toArray();

        $email     = $data['email'] ?? '';
        $password  = $data['password'] ?? '';
        $deviceId  = $data['device_id'] ?? 'unknown';
        $userAgent = $request->headers->get('User-Agent', 'unknown');

        $tokens = $this->auth->login($email, $password, $deviceId, $userAgent);

        return new JsonResponse($tokens, Response::HTTP_OK);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->toArray();

        $refreshToken = $data['refresh_token'] ?? '';
        $deviceId     = $data['device_id'] ?? 'unknown';
        $userAgent    = $request->headers->get('User-Agent', 'unknown');

        $tokens = $this->auth->refresh($refreshToken, $deviceId, $userAgent);

        return new JsonResponse($tokens, Response::HTTP_OK);
    }

    public function logout(Request $request): Response
    {
        $data = $request->toArray();
        $refreshToken = $data['refresh_token'] ?? null;

        if ($refreshToken) {
            $this->auth->logout($refreshToken);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}