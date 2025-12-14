<?php
declare(strict_types=1);

namespace PenPay\Application\Payments\Command;

use PenPay\Domain\Shared\ValueObject\PositiveDecimal;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\ValueObject\DerivLoginId;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use InvalidArgumentException;

final class StartDepositCommand
{
    public function __construct(
        public UserId $userId,
        public DerivLoginId $userDerivLoginId,
        public PhoneNumber $phoneNumberE164,
        public PositiveDecimal $amountUsd,
        public IdempotencyKey $idempotencyKey,
        public ?string $deviceId = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            userId: UserId::fromString($data['user_id'] ?? throw new InvalidArgumentException('user_id required')),
            userDerivLoginId: DerivLoginId::fromString($data['user_deriv_login_id'] ?? throw new InvalidArgumentException('user_deriv_login_id required')),
            phoneNumberE164: PhoneNumber::fromKenyan($data['user_phone_number'] ?? throw new InvalidArgumentException('user_phone_number required')),
            amountUsd: PositiveDecimal::fromFloat($data['amount_usd'] ?? throw new InvalidArgumentException('amount_usd required')),
            idempotencyKey: IdempotencyKey::fromHeader($data['idempotency_key_header'] ?? throw new InvalidArgumentException('idempotency_key_header required')),
            deviceId: $data['device_id'] ?? null
        );
    }
}