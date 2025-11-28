<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\DerivWsGateway;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\Connector as SocketConnector;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class WsClient implements WsClientInterface
{
    private const PING_INTERVAL = 30;
    private const MAX_RECONNECT_ATTEMPTS = 10;
    private const RECONNECT_BASE_DELAY = 1; // seconds

    private ?WebSocket $ws = null;
    private ?string $authToken = null;
    private int $nextReqId = 1;
    private bool $connecting = false;
    private int $reconnectAttempts = 0;
    private ?TimerInterface $pingTimer = null;

    /** @var array<int, Deferred> */
    private array $pending = [];

    private string $wsUrl;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 20,
        array $derivConfig = [] 

    ) {
        $appId = $derivConfig['app_id'] ?? '1089';
        $this->wsUrl = $derivConfig['ws_url'] ?? "wss://ws.binaryws.com/websockets/v3?app_id={$appId}";

        $this->logger->info('WsClient: Connected to Deriv', [
            'app_id' => $appId,
            'url' => $this->wsUrl
        ]);

        $this->ensureConnected();
    }

    public function authorize(string $token): void
    {
        if ($this->authToken === $token) {
            return;
        }
        $this->authToken = $token;
        $this->send(['authorize' => $token, 'req_id' => $this->nextReqId()]);
    }

    public function sendAndWait(array $payload, int $timeoutSeconds = 20): PromiseInterface
    {
        $reqId = $payload['req_id'] ?? $this->nextReqId();
        $payload['req_id'] = $reqId;

        $deferred = new Deferred();
        $this->pending[$reqId] = $deferred;

        $this->loop->addTimer($timeoutSeconds ?: $this->timeoutSeconds, function () use ($reqId) {
            if (isset($this->pending[$reqId])) {
                $d = $this->pending[$reqId];
                unset($this->pending[$reqId]);
                $d->reject(new RuntimeException("Deriv timeout (req_id: {$reqId})"));
            }
        });

        $this->ensureConnected();

        try {
            if ($this->ws && !$this->ws->isClosed()) {
                $this->ws->send(json_encode($payload));
                $this->logger->info('WsClient: Sent request', [
                    'req_id' => $reqId,
                    'method' => $payload[array_key_first($payload)] ?? 'unknown'
                ]);
            }
        } catch (Throwable $e) {
            unset($this->pending[$reqId]);
            $deferred->reject(new RuntimeException('Send failed', 0, $e));
        }

        return $deferred->promise();
    }

    public function ping(): void
    {
        $this->send(['ping' => 1]);
    }

    public function nextReqId(): int
    {
        return $this->nextReqId++;
    }

    private function ensureConnected(): void
    {
        if ($this->ws && !$this->ws->isClosed()) {
            return;
        }
        if ($this->connecting) {
            return;
        }
        $this->connecting = true;
        $this->reconnectAttempts = 0;
        $this->connect();
    }

    private function connect(): void
    {
        if ($this->reconnectAttempts >= self::MAX_RECONNECT_ATTEMPTS) {
            $this->logger->critical('WsClient: Max reconnect attempts reached');
            $this->connecting = false;
            return;
        }

        $delay = min(pow(2, $this->reconnectAttempts) * self::RECONNECT_BASE_DELAY, 30);

        $this->loop->addTimer($delay, function () {
            $this->logger->info('WsClient: Connecting...', [
                'attempt' => $this->reconnectAttempts + 1,
                'url' => $this->wsUrl
            ]);

            $connector = new Connector($this->loop, new SocketConnector($this->loop));

            $connector($this->wsUrl)->then(
                function (WebSocket $conn) {
                    $this->ws = $conn;
                    $this->connecting = false;
                    $this->reconnectAttempts = 0;

                    $this->ws->on('message', fn($msg) => $this->onMessage((string)$msg));
                    $this->ws->on('close', fn() => $this->reconnect());
                    $this->ws->on('error', fn($e) => $this->logger->error('WS error', ['exception' => $e]));

                    $this->logger->info('WsClient: Connected successfully');

                    $this->startPingTimer();

                    if ($this->authToken) {
                        $this->authorize($this->authToken);
                    }
                },
                function (Throwable $e) {
                    $this->logger->error('WsClient: Connection failed', ['error' => $e->getMessage()]);
                    $this->reconnect();
                }
            );
        });
    }

    private function reconnect(): void
    {
        $this->ws = null;
        $this->stopPingTimer();
        $this->reconnectAttempts++;
        $this->logger->warning('WsClient: Connection lost. Reconnecting...', [
            'attempt' => $this->reconnectAttempts
        ]);
        $this->connect();
    }

    private function onMessage(string $msg): void
    {
        $data = json_decode($msg, true) ?? [];

        // Handle pong
        if (isset($data['pong'])) {
            $this->logger->debug('WsClient: Received pong');
            return;
        }

        $reqId = $data['req_id'] ?? null;
        if (!$reqId || !isset($this->pending[$reqId])) {
            $this->logger->warning('WsClient: Received message with unknown req_id', compact('reqId'));
            return;
        }

        $deferred = $this->pending[$reqId];
        unset($this->pending[$reqId]);

        if (isset($data['error'])) {
            $deferred->reject(new RuntimeException($data['error']['message'] ?? 'Deriv API error'));
        } else {
            $deferred->resolve($data);
        }
    }

    private function send(array $payload): void
    {
        if (!$this->ws || $this->ws->isClosed()) {
            $this->ensureConnected();
            return;
        }

        try {
            $this->ws->send(json_encode($payload));
        } catch (Throwable $e) {
            $this->logger->error('WsClient: Failed to send payload', ['error' => $e->getMessage()]);
            $this->reconnect();
        }
    }

    private function startPingTimer(): void
    {
        $this->stopPingTimer();
        $this->pingTimer = $this->loop->addPeriodicTimer(self::PING_INTERVAL, fn() => $this->ping());
        $this->logger->debug('WsClient: Ping timer started (30s interval)');
    }

    private function stopPingTimer(): void
    {
        if ($this->pingTimer) {
            $this->loop->cancelTimer($this->pingTimer);
            $this->pingTimer = null;
        }
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
}