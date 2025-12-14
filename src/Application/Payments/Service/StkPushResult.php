<?php
declare(strict_types=1);

namespace PenPay\Application\Payments\Service;

final class StkPushResult
{
    public function __construct(
        private readonly string $requestId,
        private readonly array $raw
    ) {}

    public function requestId(): string { return $this->requestId; }
    public function toArray(): array { return $this->raw; }
}