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

    public static function failure(string $error, array $raw, ?int $resultCode = null): self
    {
        return new self(false, null, $resultCode, $error, $raw);
    }

    public static function fromMpesaResponse(array $response): self
    {
        if (isset($response['ResponseCode'])) {
            $responseCode = $response['ResponseCode'];
            
            // B2C API returns string codes like '0', '1', etc.
            if ($responseCode === '0' || $responseCode === 0) {
                $receipt = $response['TransactionReceipt'] 
                    ?? $response['TransactionID'] 
                    ?? $response['ConversationID'] 
                    ?? 'UNKNOWN';
                    
                return self::success((string)$receipt, (int)$responseCode, $response);
            }
            
            $errorMessage = $response['ResponseDescription'] ?? 'Unknown M-Pesa error';
            return self::failure($errorMessage, $response, (int)$responseCode);
        }
        
        // Fallback for unexpected format
        return self::failure('Invalid M-Pesa response format', $response);
    }

    public function isSuccess(): bool { return $this->success; }
    public function receiptNumber(): ?string { return $this->receiptNumber; }
    public function resultCode(): ?int { return $this->resultCode; }
    public function errorMessage(): ?string { return $this->errorMessage; }
    public function raw(): array { return $this->rawResponse; }
}