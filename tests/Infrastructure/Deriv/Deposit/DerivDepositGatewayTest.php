<?php
declare(strict_types=1);

namespace PenPay\Tests\Infrastructure\Deriv\Deposit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PenPay\Infrastructure\Deriv\Deposit\DerivDepositGateway;
use PenPay\Domain\Payments\Entity\DerivResult;
use PenPay\Infrastructure\DerivWsGateway\WsClient;
use React\Promise\PromiseInterface;

final class DerivDepositGatewayTest extends TestCase
{
    private WsClient&MockObject $wsClient;
    private DerivDepositGateway $gateway;

    protected function setUp(): void
    {
        $this->wsClient = $this->createMock(WsClient::class);
        $this->gateway  = new DerivDepositGateway($this->wsClient);
    }

    /** @test */
    public function it_returns_success_on_valid_response(): void
    {
        $response = [
            'paymentagent_transfer' => 1, // 1 = success, 2 = dry-run success
            'client_to_full_name' => 'John Doe',
            'client_to_loginid' => 'CR123456',
            'transaction_id' => 123456789,
            'msg_type' => 'paymentagent_transfer',
            'echo_req' => [
                'paymentagent_transfer' => 1,
                'amount' => 50.0,
                'currency' => 'USD',
                'transfer_to' => 'CR123456',
                'description' => 'TX123',
                'req_id' => 1
            ],
            'req_id' => 1
        ];

        $this->wsClient
            ->expects($this->once())
            ->method('sendAndWait')
            ->with($this->callback(function ($payload) {
                return $payload['paymentagent_transfer'] === 1
                    && $payload['transfer_to'] === 'CR123456'
                    && $payload['amount'] === 50.0
                    && $payload['currency'] === 'USD'
                    && $payload['description'] === 'TX123'
                    && isset($payload['req_id']);
            }))
            ->willReturn($this->createPromise($response));

        $this->wsClient
            ->expects($this->once())
            ->method('nextReqId')
            ->willReturn(1);

        $promise = $this->gateway->deposit(
            'CR123456',  // This becomes transfer_to
            50.0,
            'token123',  // This is handled via authorization before the call
            'TX123'
        );

        $result = $this->awaitPromise($promise);

        $this->assertInstanceOf(DerivResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('123456789', $result->transferId());  // transaction_id becomes transferId
        $this->assertSame('123456789', $result->txnId());       // transaction_id also becomes txnId
        $this->assertSame(50.0, $result->amountUsd()->toDecimal());
    }

    /** @test */
    public function it_returns_success_on_dry_run_response(): void
    {
        $response = [
            'paymentagent_transfer' => 2, // 2 = dry-run success
            'client_to_full_name' => 'Jane Smith',
            'client_to_loginid' => 'CR789012',
            'transaction_id' => 987654321,
            'msg_type' => 'paymentagent_transfer',
            'echo_req' => [
                'paymentagent_transfer' => 1,
                'amount' => 25.0,
                'currency' => 'USD',
                'transfer_to' => 'CR789012',
                'description' => 'DRY_RUN_123',
                'req_id' => 1
            ],
            'req_id' => 1
        ];

        $this->wsClient
            ->expects($this->once())
            ->method('sendAndWait')
            ->willReturn($this->createPromise($response));

        $this->wsClient
            ->expects($this->once())
            ->method('nextReqId')
            ->willReturn(1);

        $promise = $this->gateway->deposit(
            'CR789012',
            25.0,
            'token123',
            'DRY_RUN_123'
        );

        $result = $this->awaitPromise($promise);

        $this->assertInstanceOf(DerivResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('987654321', $result->transferId());
        $this->assertSame('987654321', $result->txnId());
        $this->assertSame(25.0, $result->amountUsd()->toDecimal());
    }

    /** @test */
    public function it_returns_failure_on_error_response(): void
    {
        $response = [
            'error' => [
                'code' => 'PaymentAgentTransferError',
                'message' => 'Transfer failed: insufficient funds'
            ],
            'msg_type' => 'paymentagent_transfer',
            'req_id' => 1
        ];

        $this->wsClient
            ->expects($this->once())
            ->method('sendAndWait')
            ->willReturn($this->createPromise($response));

        $this->wsClient
            ->expects($this->once())
            ->method('nextReqId')
            ->willReturn(1);

        $promise = $this->gateway->deposit(
            'CR123456',
            50.0,
            'token123',
            'TX123'
        );

        $result = $this->awaitPromise($promise);

        $this->assertInstanceOf(DerivResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertSame('Transfer failed: insufficient funds', $result->errorMessage());
    }

    /** @test */
    public function it_returns_failure_on_unexpected_msg_type(): void
    {
        $response = [
            'msg_type' => 'balance', // Wrong message type
            'balance' => 1000.00,
            'req_id' => 1
        ];

        $this->wsClient
            ->expects($this->once())
            ->method('sendAndWait')
            ->willReturn($this->createPromise($response));

        $this->wsClient
            ->expects($this->once())
            ->method('nextReqId')
            ->willReturn(1);

        $promise = $this->gateway->deposit(
            'CR123456',
            50.0,
            'token123',
            'TX123'
        );

        $result = $this->awaitPromise($promise);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Unexpected response type', $result->errorMessage());
    }

    /** @test */
    public function it_returns_failure_on_failed_transfer_status(): void
    {
        $response = [
            'paymentagent_transfer' => 0, 
            'msg_type' => 'paymentagent_transfer',
            'req_id' => 1
        ];

        $this->wsClient
            ->expects($this->once())
            ->method('sendAndWait')
            ->willReturn($this->createPromise($response));

        $this->wsClient
            ->expects($this->once())
            ->method('nextReqId')
            ->willReturn(1);

        $promise = $this->gateway->deposit(
            'CR123456',
            50.0,
            'token123',
            'TX123'
        );

        $result = $this->awaitPromise($promise);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Transfer was not successful', $result->errorMessage());
    }

    /** @test */
    public function it_returns_failure_on_missing_transaction_id(): void
    {
        $response = [
            'paymentagent_transfer' => 1, // Success
            'client_to_full_name' => 'John Doe',
            'client_to_loginid' => 'CR123456',
            // Missing transaction_id
            'msg_type' => 'paymentagent_transfer',
            'req_id' => 1
        ];

        $this->wsClient
            ->expects($this->once())
            ->method('sendAndWait')
            ->willReturn($this->createPromise($response));

        $this->wsClient
            ->expects($this->once())
            ->method('nextReqId')
            ->willReturn(1);

        $promise = $this->gateway->deposit(
            'CR123456',
            50.0,
            'token123',
            'TX123'
        );

        $result = $this->awaitPromise($promise);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Missing transaction ID', $result->errorMessage());
    }

    /** @test */
    public function it_passes_metadata_to_ws_client(): void
    {
        $response = [
            'paymentagent_transfer' => 1,
            'client_to_full_name' => 'John Doe',
            'client_to_loginid' => 'CR123456',
            'transaction_id' => 123456789,
            'msg_type' => 'paymentagent_transfer',
            'req_id' => 1
        ];

        $metadata = ['phone' => '254712345678', 'user_id' => 'user123'];

        $this->wsClient
            ->expects($this->once())
            ->method('sendAndWait')
            ->with($this->callback(function ($payload) use ($metadata) {
                return $payload['paymentagent_transfer'] === 1
                    && $payload['transfer_to'] === 'CR123456'
                    && $payload['amount'] === 50.0
                    && $payload['currency'] === 'USD'
                    && $payload['description'] === 'TX123'
                    && $payload['passthrough'] === $metadata  // Metadata should be in passthrough
                    && isset($payload['req_id']);
            }))
            ->willReturn($this->createPromise($response));

        $this->wsClient
            ->expects($this->once())
            ->method('nextReqId')
            ->willReturn(1);

        $promise = $this->gateway->deposit(
            'CR123456',
            50.0,
            'token123',
            'TX123',
            $metadata
        );

        $result = $this->awaitPromise($promise);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('123456789', $result->transferId());
    }

    /** @test */
    public function it_handles_network_errors(): void
    {
        $this->wsClient
            ->expects($this->once())
            ->method('sendAndWait')
            ->willReturn($this->createRejectedPromise(new \Exception('Connection timeout')));

        $this->wsClient
            ->expects($this->once())
            ->method('nextReqId')
            ->willReturn(1);

        $promise = $this->gateway->deposit(
            'CR123456',
            50.0,
            'token123',
            'TX123'
        );

        $result = $this->awaitPromise($promise);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Connection timeout', $result->errorMessage());
    }

    /** @test */
    public function it_handles_empty_metadata_correctly(): void
    {
        $response = [
            'paymentagent_transfer' => 1,
            'client_to_full_name' => 'John Doe',
            'client_to_loginid' => 'CR123456',
            'transaction_id' => 123456789,
            'msg_type' => 'paymentagent_transfer',
            'req_id' => 1
        ];

        $this->wsClient
            ->expects($this->once())
            ->method('sendAndWait')
            ->with($this->callback(function ($payload) {
                return $payload['paymentagent_transfer'] === 1
                    && $payload['transfer_to'] === 'CR123456'
                    && $payload['amount'] === 50.0
                    && $payload['currency'] === 'USD'
                    && $payload['description'] === 'TX123'
                    && !isset($payload['passthrough'])  // No passthrough when metadata is empty
                    && isset($payload['req_id']);
            }))
            ->willReturn($this->createPromise($response));

        $this->wsClient
            ->expects($this->once())
            ->method('nextReqId')
            ->willReturn(1);

        $promise = $this->gateway->deposit(
            'CR123456',
            50.0,
            'token123',
            'TX123',
            [] // Empty metadata
        );

        $result = $this->awaitPromise($promise);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('123456789', $result->transferId());
    }

    /** @test */
    public function it_handles_different_amounts_correctly(): void
    {
        $response = [
            'paymentagent_transfer' => 1,
            'client_to_full_name' => 'John Doe',
            'client_to_loginid' => 'CR123456',
            'transaction_id' => 999888777,
            'msg_type' => 'paymentagent_transfer',
            'req_id' => 1
        ];

        $this->wsClient
            ->expects($this->once())
            ->method('sendAndWait')
            ->with($this->callback(function ($payload) {
                return $payload['amount'] === 123.45; // Specific amount
            }))
            ->willReturn($this->createPromise($response));

        $this->wsClient
            ->expects($this->once())
            ->method('nextReqId')
            ->willReturn(1);

        $promise = $this->gateway->deposit(
            'CR123456',
            123.45, // Different amount
            'token123',
            'TX123'
        );

        $result = $this->awaitPromise($promise);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(123.45, $result->amountUsd()->toDecimal());
    }

    /** @test */
    public function it_handles_different_references_correctly(): void
    {
        $response = [
            'paymentagent_transfer' => 1,
            'client_to_full_name' => 'John Doe',
            'client_to_loginid' => 'CR123456',
            'transaction_id' => 555666777,
            'msg_type' => 'paymentagent_transfer',
            'req_id' => 1
        ];

        $this->wsClient
            ->expects($this->once())
            ->method('sendAndWait')
            ->with($this->callback(function ($payload) {
                return $payload['description'] === 'SPECIAL_REF_001'; // Specific reference
            }))
            ->willReturn($this->createPromise($response));

        $this->wsClient
            ->expects($this->once())
            ->method('nextReqId')
            ->willReturn(1);

        $promise = $this->gateway->deposit(
            'CR123456',
            50.0,
            'token123',
            'SPECIAL_REF_001' // Different reference
        );

        $result = $this->awaitPromise($promise);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('555666777', $result->transferId());
    }

    private function createPromise($value): PromiseInterface
    {
        $deferred = new \React\Promise\Deferred();
        $deferred->resolve($value);
        return $deferred->promise();
    }

    private function createRejectedPromise(\Throwable $error): PromiseInterface
    {
        $deferred = new \React\Promise\Deferred();
        $deferred->reject($error);
        return $deferred->promise();
    }

    private function awaitPromise(PromiseInterface $promise): mixed
    {
        $resolvedValue = null;
        $resolved = false;
        $exception = null;

        $promise->then(
            function ($value) use (&$resolvedValue, &$resolved) {
                $resolvedValue = $value;
                $resolved = true;
            },
            function (\Throwable $e) use (&$exception, &$resolved) {
                $exception = $e;
                $resolved = true;
            }
        );

        // Simple sync wait for tests
        while (!$resolved) {
            usleep(1000);
        }

        if ($exception) {
            throw $exception;
        }

        return $resolvedValue;
    }
}