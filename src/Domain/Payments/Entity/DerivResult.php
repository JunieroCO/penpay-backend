<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Entity;

use PenPay\Domain\Wallet\ValueObject\Money;
use RuntimeException;
use InvalidArgumentException;

final readonly class DerivResult
{
    private function __construct(
        public bool $success,
        public ?string $transferId,
        public ?string $txnId,
        public ?Money $amountUsd,
        public ?string $errorMessage,
        public array $rawResponse,
    ) {}

    public static function success(
        string $transferId,
        string $txnId,
        Money $amountUsd,
        array $rawResponse = []
    ): self {
        if (!$amountUsd->currency->isUsd()) {
            throw new InvalidArgumentException('Deriv transfer result must be reported in USD (Money::USD).');
        }

        return new self(
            success: true,
            transferId: $transferId,
            txnId: $txnId,
            amountUsd: $amountUsd,
            errorMessage: null,
            rawResponse: $rawResponse
        );
    }

    public static function failure(
        string $errorMessage,
        array $rawResponse = []
    ): self {
        return new self(
            success: false,
            transferId: null,
            txnId: null,
            amountUsd: null,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse
        );
    }

    public function isSuccess(): bool { return $this->success; }
    public function isFailure(): bool { return !$this->success; }

    public function transferId(): string
    {
        $this->ensureSuccess();
        return $this->transferId;
    }

    public function txnId(): string
    {
        $this->ensureSuccess();
        return $this->txnId;
    }

    public function amountUsd(): Money
    {
        $this->ensureSuccess();
        return $this->amountUsd;
    }

    public function errorMessage(): string
    {
        $this->ensureFailure();
        return $this->errorMessage;
    }

    public function raw(): array { return $this->rawResponse; }

    private function ensureSuccess(): void
    {
        if (!$this->success) {
            throw new RuntimeException('Cannot access success-fields on a failed DerivTransferResult.');
        }
    }

    private function ensureFailure(): void
    {
        if ($this->success) {
            throw new RuntimeException('Cannot access error message on a successful DerivTransferResult.');
        }
    }
}