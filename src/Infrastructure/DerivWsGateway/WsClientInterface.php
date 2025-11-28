<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\DerivWsGateway;

use React\Promise\PromiseInterface;
use Psr\Log\LoggerInterface;

interface WsClientInterface
{
    public function authorize(string $token): void;
    public function sendAndWait(array $payload, int $timeoutSeconds = 20): PromiseInterface;
    public function nextReqId(): int;
    public function ping(): void;

    public function getLogger(): LoggerInterface;
}