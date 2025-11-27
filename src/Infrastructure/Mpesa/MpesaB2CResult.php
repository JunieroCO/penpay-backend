<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Mpesa;

final class MpesaB2CResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $receiptNumber = null,
        public readonly ?int $resultCode = null,
        public readonly ?string $errorMessage = null,
        private readonly array $rawResponse = []
    ) {}

    public static function success(string $receipt, int $resultCode, array $raw): self
    {
        return new self(true, $receipt, $resultCode, null, $raw);
    }

    public static function failure(string $error, array $raw): self
    {
        return new self(false, null, null, $error, $raw);
    }


    public static function fromMpesaResponse(array $response): self
    {
        $resultCode = $response['ResultCode'] ?? null;
        
        if ($resultCode === 0) {
            return self::success(
                $response['TransactionReceipt'] ?? $response['ConversationID'] ?? 'UNKNOWN',
                $resultCode,
                $response
            );
        }
        
        return self::failure(
            $response['ResultDesc'] ?? 'Unknown M-Pesa error',
            $response
        );
    }

    public function isSuccess(): bool { return $this->success; }
    public function receiptNumber(): ?string { return $this->receiptNumber; }
    public function resultCode(): ?int { return $this->resultCode; }
    public function errorMessage(): ?string { return $this->errorMessage; }
    public function raw(): array { return $this->rawResponse; }
}