<?php
declare(strict_types=1);

namespace PenPay\Application\Deposit\DTO;

use PenPay\Domain\Shared\ValueObject\PositiveDecimal;
use PenPay\Domain\Shared\Kernel\UserId;
use InvalidArgumentException;

final readonly class DepositRequestDTO
{
    private function __construct(
        public UserId $userId,
        public PositiveDecimal $amountUsd,
        public string $userDerivLoginId,
        public ?string $deviceId = null,
        public ?string $idempotencyKey = null,
    ) {}

    public static function fromArray(array $data, ?string $deviceId = null): self
    {
        return new self(
            userId: UserId::fromString($data['user_id'] ?? throw new InvalidArgumentException('user_id required')),
            amountUsd: PositiveDecimal::fromFloat($data['amount_usd'] ?? throw new InvalidArgumentException('amount_usd required')),
            userDerivLoginId: $data['user_deriv_login_id'] ?? throw new InvalidArgumentException('user_deriv_login_id required'),
            deviceId: $data['device_id'] ?? $deviceId,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );
    }
}