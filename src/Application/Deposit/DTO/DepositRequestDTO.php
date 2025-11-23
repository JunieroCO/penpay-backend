<?php
declare(strict_types=1);

namespace PenPay\Application\Deposit\DTO;

use PenPay\Domain\Shared\ValueObject\PositiveDecimal;
use PenPay\Domain\Shared\Kernel\UserId;
use InvalidArgumentException;

final readonly class DepositRequestDTO
{
    private function __construct(
        public UserId          $userId,
        public PositiveDecimal $amountUsd,
        public ?string         $deviceId = null,
        public ?string         $idempotencyKey = null,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data, ?string $deviceId = null): self
    {
        $userId = $data['user_id'] ?? throw new InvalidArgumentException('user_id is required');
        $amount = $data['amount_usd'] ?? throw new InvalidArgumentException('amount_usd is required');

        if (!is_string($userId) || trim($userId) === '') {
            throw new InvalidArgumentException('user_id must be a non-empty string');
        }
        if (!is_numeric($amount) || $amount <= 0) {
            throw new InvalidArgumentException('amount_usd must be a positive number');
        }

        return new self(
            userId:         UserId::fromString($userId),
            amountUsd:      PositiveDecimal::fromFloat((float) $amount),
            deviceId:       $data['device_id'] ?? $deviceId,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );
    }
}