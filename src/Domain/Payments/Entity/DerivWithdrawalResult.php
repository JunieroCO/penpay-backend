<?php
declare(strict_types=1);

namespace PenPay\Domain\Payments\Entity;

use RuntimeException;

/**
 * Immutable result of a Deriv Payment Agent withdrawal
 */
final readonly class DerivWithdrawalResult
{
    private function __construct(
        public bool $success,
        public ?string $transferId,
        public ?string $txnId,
        public ?float $amountUsd,
        public ?string $errorMessage,
        public array $rawResponse,
    ) {}

    public static function success(
        string $transferId,
        string $txnId,
        float $amountUsd,
        array $rawResponse = []
    ): self {
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

    public function transferId(): string { $this->ensureSuccess(); return $this->transferId; }
    public function txnId(): string       { $this->ensureSuccess(); return $this->txnId; }
    public function amountUsd(): float    { $this->ensureSuccess(); return $this->amountUsd; }
    public function errorMessage(): ?string { return $this->errorMessage; }
    public function raw(): array { return $this->rawResponse; }

    private function ensureSuccess(): void
    {
        if (!$this->success) {
            throw new RuntimeException('Cannot access success fields on failed withdrawal');
        }
    }
}