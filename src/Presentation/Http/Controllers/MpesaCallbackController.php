<?php
declare(strict_types=1);

namespace PenPay\Presentation\Http\Controllers;

use PenPay\Application\Callback\MpesaCallbackVerifier;
use PenPay\Workers\MpesaCallbackWorker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MpesaCallbackController
{
    public function __construct(
        private readonly MpesaCallbackVerifier $verifier,
        private readonly MpesaCallbackWorker $worker
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = json_decode($request->getBody()->getContents(), true) ?? [];

        try {
            $verified = $this->verifier->verify($payload);
            $this->worker->handle($verified);

            $response->getBody()->write(json_encode(['status' => 'success']));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            // NEVER return error to M-Pesa — they will retry
            $response->getBody()->write(json_encode(['status' => 'error']));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }
    }
}