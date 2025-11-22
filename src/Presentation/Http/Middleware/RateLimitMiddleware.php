<?php
declare(strict_types=1);

namespace PenPay\Presentation\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Response;
use Redis;
use RedisException;

final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    private const DEFAULT_LIMIT = 60;        // requests
    private const DEFAULT_WINDOW = 60;       // seconds

    public function __construct(
        private Redis $redis,
        private int $limit = self::DEFAULT_LIMIT,
        private int $window = self::DEFAULT_WINDOW,
        private string $prefix = 'ratelimit:'
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->getClientIp($request);
        $path = $request->getUri()->getPath();
        $key = $this->prefix . md5($ip . ':' . $path);

        try {
            $current = $this->redis->incr($key);
            if ($current === 1) {
                $this->redis->expire($key, $this->window);
            }

            if ($current > $this->limit) {
                return new Response(
                    429,
                    [
                        'Content-Type' => 'application/json',
                        'Retry-After' => $this->window,
                        'X-RateLimit-Limit' => (string) $this->limit,
                        'X-RateLimit-Remaining' => '0',
                        'X-RateLimit-Reset' => (string) (time() + $this->window),
                    ],
                    json_encode(['error' => 'too_many_requests', 'message' => 'Rate limit exceeded'], JSON_UNESCAPED_SLASHES)
                );
            }

            return $handler->handle($request)->withHeader('X-RateLimit-Remaining', (string) ($this->limit - $current));
        } catch (RedisException $e) {
            // Fail open — never break the app
            return $handler->handle($request);
        }
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        return $request->getHeaderLine('X-Forwarded-For')
            ?: $request->getHeaderLine('X-Real-IP')
            ?: $request->getServerParams()['REMOTE_ADDR']
            ?: 'unknown';
    }
}