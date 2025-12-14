<?php
declare(strict_types=1);

namespace PenPay\Application\Payments\Command;

use PenPay\Domain\Shared\ValueObject\PositiveDecimal;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\ValueObject\DerivLoginId;
use PenPay\Domain\Shared\ValueObject\PhoneNumber;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Payments\ValueObject\OneTimeVerificationCode;
use InvalidArgumentException;

final readonly class StartWithdrawalCommand
{
    public function __construct(
        public UserId $userId,
        public PositiveDecimal $amountUsdCents,             
        public IdempotencyKey $idempotencyKeyHeader,    
        public OneTimeVerificationCode $withdrawalVerificationCode, 
        public DerivLoginId $userDerivLoginId,        
        public PhoneNumber $phoneNumberE164          
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            userId: UserId::fromString($data['user_id'] ?? throw new InvalidArgumentException('user_id required')),
            amountUsdCents: PositiveDecimal::fromFloat($data['amount_usd_cents'] ?? throw new InvalidArgumentException('amount_usd_cents required')),
            idempotencyKeyHeader: IdempotencyKey::fromHeader($data['idempotency_key_header'] ?? throw new InvalidArgumentException('idempotency_key_header required')),
            withdrawalVerificationCode: OneTimeVerificationCode::fromString($data['withdrawal_verification_code'] ?? throw new InvalidArgumentException('withdrawal_verification_code required')),
            userDerivLoginId: DerivLoginId::fromString($data['user_deriv_login_id'] ?? throw new InvalidArgumentException('user_deriv_login_id required')),
            phoneNumberE164: PhoneNumber::fromKenyan($data['user_phone_number'] ?? throw new InvalidArgumentException('user_phone_number required')),
        );
    }
}