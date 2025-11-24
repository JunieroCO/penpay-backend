<?php
declare(strict_types=1);

namespace Tests\Application\Callback;

use PenPay\Application\Callback\MpesaCallbackVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MpesaCallbackVerifierTest extends TestCase
{
    private MpesaCallbackVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new MpesaCallbackVerifier();
    }

    public function test_successful_callback_is_verified(): void
    {
        $payload = json_decode(
            file_get_contents(__DIR__ . '/fixtures/mpesa_success_callback.json'),
            true
        );

        $result = $this->verifier->verify($payload);

        $this->assertSame('success', $result['status']);
        $this->assertSame('RKL9ABC123', $result['mpesa_receipt']);
        $this->assertSame('254712345678', $result['phone']);
        $this->assertSame(50000, $result['amount_kes_cents']);
        $this->assertSame('0191e3d4-5678-7abc-aef0-123456789abc', $result['transaction_id']);
    }

    public function test_failed_callback_is_handled(): void
    {
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'ws_CO_FAILED123',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'transaction_id', 'Value' => '0191e3d4-5678-7abc-aef0-123456789abc']
                        ]
                    ]
                ]
            ]
        ];

        $result = $this->verifier->verify($payload);

        $this->assertSame('failed', $result['status']);
        $this->assertNull($result['mpesa_receipt'] ?? null);
        $this->assertSame(0, $result['amount_kes_cents']);
        $this->assertSame('0191e3d4-5678-7abc-aef0-123456789abc', $result['transaction_id']);
    }

    public function test_invalid_payload_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->verifier->verify([]);
    }

    public function test_missing_transaction_id_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transaction_id not found');

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'ws_CO_123',
                    'ResultCode' => 0,
                    'CallbackMetadata' => ['Item' => []]
                ]
            ]
        ];

        $this->verifier->verify($payload);
    }
}