<?php
declare(strict_types=1);

namespace PenPay\Application\Withdrawal\DTO;

use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\ValueObject\PositiveDecimal;
use InvalidArgumentException;

final readonly class WithdrawalRequestDTO
{
    private function __construct(
        public UserId $userId,
        public PositiveDecimal $amountUsd,
        public string $verificationCode,
        public string $userDerivLoginId,
        public ?string $deviceId = null,
        public ?string $idempotencyKey = null,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data, ?string $deviceId = null): self
    {
        $userId = $data['user_id'] ?? throw new InvalidArgumentException('user_id is required');
        $amount = $data['amount_usd'] ?? throw new InvalidArgumentException('amount_usd is required');
        $code   = $data['verification_code'] ?? throw new InvalidArgumentException('verification_code is required');
        $loginId = $data['user_deriv_login_id'] ?? throw new InvalidArgumentException('user_deriv_login_id is required');

        if (!is_string($userId) || trim($userId) === '') {
            throw new InvalidArgumentException('user_id must be a non-empty string');
        }

        if (!is_numeric($amount) || $amount <= 0) {
            throw new InvalidArgumentException('amount_usd must be a positive number');
        }

        if (!is_string($code) || strlen(trim($code)) !== 6) {
            throw new InvalidArgumentException('verification_code must be exactly 6 characters');
        }

        if (!is_string($loginId) || trim($loginId) === '') {
            throw new InvalidArgumentException('user_deriv_login_id must be a non-empty string');
        }

        return new self(
            userId: UserId::fromString($userId),
            amountUsd: PositiveDecimal::fromFloat((float) $amount),
            verificationCode: trim($code),
            userDerivLoginId: $loginId,
            deviceId: $data['device_id'] ?? $deviceId,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );
    }

    public function toCents(): int
    {
        return (int) round($this->amountUsd->toFloat() * 100);
    }
}