<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Entity;

use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DerivTransfer
{
    public function __construct(
        public TransactionId $transactionId,
        public string $fromLoginId,
        public string $toLoginId,
        public Money $amountUsd,
        public string $derivTransferId,
        public string $derivTxnId,
        public DateTimeImmutable $executedAt,
        public array $rawResponse = [],
        public ?string $withdrawalVerificationCode = null,
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if (!$this->amountUsd->currency->isUsd()) {
            throw new InvalidArgumentException('Deriv transfer amount must be in USD.');
        }

        if ($this->fromLoginId === $this->toLoginId) {
            throw new InvalidArgumentException('Cannot transfer to the same Deriv login id.');
        }

        if ($this->derivTransferId === '') {
            throw new InvalidArgumentException('Deriv transfer ID cannot be empty.');
        }

        if ($this->derivTxnId === '') {
            throw new InvalidArgumentException('Deriv transaction ID cannot be empty.');
        }

        // Withdrawal flows require a verified code (your schema: withdrawal_code VARCHAR(6))
        if ($this->isWithdrawal() && ($this->withdrawalVerificationCode === null || $this->withdrawalVerificationCode === '')) {
            throw new InvalidArgumentException('Withdrawal requires a verification code.');
        }

        if ($this->withdrawalVerificationCode !== null && !preg_match('/^[A-Z0-9]{6}$/', strtoupper($this->withdrawalVerificationCode))) {
            throw new InvalidArgumentException('Withdrawal verification code must be 6 alphanumeric characters (A-Z0-9).');
        }
    }

    public static function forDeposit(
        TransactionId $transactionId,
        string $paymentAgentLoginId,
        string $userDerivLoginId,
        Money $amountUsd,
        string $derivTransferId,
        string $derivTxnId,
        ?DateTimeImmutable $executedAt = null,
        array $rawResponse = []
    ): self {
        return new self(
            transactionId: $transactionId,
            fromLoginId: $paymentAgentLoginId,
            toLoginId: $userDerivLoginId,
            amountUsd: $amountUsd,
            derivTransferId: $derivTransferId,
            derivTxnId: $derivTxnId,
            executedAt: $executedAt ?? new DateTimeImmutable(),
            rawResponse: $rawResponse,
            withdrawalVerificationCode: null
        );
    }

    public static function forWithdrawal(
        TransactionId $transactionId,
        string $userDerivLoginId,
        string $paymentAgentLoginId,
        Money $amountUsd,
        string $derivTransferId,
        string $derivTxnId,
        string $withdrawalVerificationCode,
        ?DateTimeImmutable $executedAt = null,
        array $rawResponse = []
    ): self {
        return new self(
            transactionId: $transactionId,
            fromLoginId: $userDerivLoginId,
            toLoginId: $paymentAgentLoginId,
            amountUsd: $amountUsd,
            derivTransferId: $derivTransferId,
            derivTxnId: $derivTxnId,
            executedAt: $executedAt ?? new DateTimeImmutable(),
            rawResponse: $rawResponse,
            withdrawalVerificationCode: strtoupper($withdrawalVerificationCode)
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            transactionId: TransactionId::fromString($data['transaction_id']),
            fromLoginId: $data['from_login_id'],
            toLoginId: $data['to_login_id'],
            amountUsd: Money::usd((int)$data['amount_usd_cents']),
            derivTransferId: $data['deriv_transfer_id'],
            derivTxnId: $data['deriv_txn_id'],
            executedAt: new DateTimeImmutable($data['executed_at']),
            rawResponse: $data['raw_response'] ?? [],
            withdrawalVerificationCode: $data['withdrawal_code'] ?? null,
        );
    }

    public function isDeposit(): bool
    {
        return $this->withdrawalVerificationCode === null;
    }

    public function isWithdrawal(): bool
    {
        return $this->withdrawalVerificationCode !== null;
    }
}