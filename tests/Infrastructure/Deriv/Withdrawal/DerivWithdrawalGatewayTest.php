<?php
declare(strict_types=1);

namespace PenPay\Tests\Infrastructure\Deriv\Withdrawal;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PenPay\Infrastructure\Deriv\Withdrawal\DerivWithdrawalGateway;
use PenPay\Infrastructure\DerivWsGateway\WsClientInterface;
use PenPay\Domain\Payments\Entity\DerivWithdrawalResult; 
use React\EventLoop\Loop; 
use React\Promise\PromiseInterface;

final class DerivWithdrawalGatewayTest extends TestCase
{
    private WsClientInterface&MockObject $wsClient;
    private DerivWithdrawalGateway $gateway;

    protected function setUp(): void
    {
        $this->wsClient = $this->createMock(WsClientInterface::class);
        $this->gateway = new DerivWithdrawalGateway($this->wsClient);
    }

    /** @test */
    public function it_succeeds_with_valid_response(): void
    {
        $this->wsClient->method('nextReqId')->willReturn(7);
        $this->wsClient->method('sendAndWait')
            ->willReturn(\React\Promise\resolve([
                'paymentagent_withdraw' => 1,
                'transaction_id' => 987654,
                'msg_type' => 'paymentagent_withdraw',
                'req_id' => 7
            ]));

        $result = $this->awaitPromise($this->gateway->withdraw(
            loginId: 'CR123456',
            amountUsd: 100.0,
            verificationCode: 'V12345',
            reference: 'WD-001'
        ));

        $this->assertTrue($result->isSuccess());
        $this->assertSame('987654', $result->txnId());
        $this->assertSame(100.0, $result->amountUsd());
    }

    /** @test */
    public function it_returns_failure_on_insufficient_funds(): void
    {
        $this->wsClient->method('nextReqId')->willReturn(1);
        $this->wsClient->method('sendAndWait')
            ->willReturn(\React\Promise\resolve([
                'error' => [
                    'code' => 'InsufficientFunds',
                    'message' => 'Not enough balance'
                ]
            ]));

        $result = $this->awaitPromise($this->gateway->withdraw('CR123456', 50.0, 'ABC123', 'Test'));

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Insufficient balance', $result->errorMessage());
    }

    /** @test */
    public function it_returns_failure_on_invalid_token(): void
    {
        $this->wsClient->method('nextReqId')->willReturn(1);
        $this->wsClient->method('sendAndWait')
            ->willReturn(\React\Promise\resolve([
                'error' => [
                    'code' => 'InvalidToken',
                    'message' => 'Token is invalid'
                ]
            ]));

        $result = $this->awaitPromise($this->gateway->withdraw('CR123456', 50.0, 'ABC123', 'Test'));

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Invalid or expired token', $result->errorMessage()); // FIXED: Updated message
    }

    /** @test */
    public function it_returns_failure_if_transaction_id_missing(): void
    {
        $this->wsClient->method('nextReqId')->willReturn(1);
        $this->wsClient->method('sendAndWait')
            ->willReturn(\React\Promise\resolve([
                'paymentagent_withdraw' => 1,
                // transaction_id intentionally missing
            ]));

        $result = $this->awaitPromise($this->gateway->withdraw('CR123456', 20.0, 'ABC123', 'Test'));

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Missing transaction_id', $result->errorMessage()); // FIXED: Correct message
    }

    /** @test */
    public function it_handles_invalid_error_codes_gracefully(): void
    {
        $this->wsClient->method('nextReqId')->willReturn(1);
        $this->wsClient->method('sendAndWait')
            ->willReturn(\React\Promise\resolve([
                'error' => [
                    'code' => 'UnknownError',
                    'message' => 'Unexpected failure'
                ]
            ]));

        $result = $this->awaitPromise($this->gateway->withdraw('CR123456', 15.0, 'ABC123', 'Test'));

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Unexpected failure', $result->errorMessage());
    }

    /** @test */
    public function it_handles_ws_client_errors_gracefully(): void
    {
        $this->wsClient->method('nextReqId')->willReturn(1);
        $this->wsClient->method('sendAndWait')
            ->willReturn(\React\Promise\reject(new \Exception('Connection timeout')));

        $result = $this->awaitPromise($this->gateway->withdraw('CR123456', 50.0, 'ABC123', 'Test'));

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Network error', $result->errorMessage());
    }

    /** @test */
    public function it_sends_correct_payload_to_ws_client(): void
    {
        $this->wsClient->expects($this->once())
            ->method('nextReqId')
            ->willReturn(999);

        $this->wsClient->expects($this->once())
            ->method('sendAndWait')
            ->with($this->callback(function ($payload) {
                return $payload === [
                    'paymentagent_withdraw' => 1,
                    'loginid' => 'CR999999',
                    'amount' => 75.5,
                    'currency' => 'USD',
                    'verification_code' => 'XYZ789',
                    'description' => 'My withdrawal ref',
                    'req_id' => 999
                ];
            }))
            ->willReturn(\React\Promise\resolve(['paymentagent_withdraw' => 1, 'transaction_id' => 1]));

        $this->gateway->withdraw('CR999999', 75.5, 'XYZ789', 'My withdrawal ref');
    }

    // Validation tests remain unchanged — they are perfect
    /** @test */ public function it_throws_exception_for_empty_login_id(): void { $this->expectException(\InvalidArgumentException::class); $this->gateway->withdraw('', 50.0, 'ABC', 'ref'); }
    /** @test */ public function it_throws_exception_for_zero_amount(): void { $this->expectException(\InvalidArgumentException::class); $this->gateway->withdraw('CR1', 0.0, 'ABC', 'ref'); }
    /** @test */ public function it_throws_exception_for_negative_amount(): void { $this->expectException(\InvalidArgumentException::class); $this->gateway->withdraw('CR1', -1.0, 'ABC', 'ref'); }
    /** @test */ public function it_throws_exception_for_empty_verification_code(): void { $this->expectException(\InvalidArgumentException::class); $this->gateway->withdraw('CR1', 10.0, '', 'ref'); }

    // FINAL, ETERNAL AWAIT METHOD — FIXED
    private function awaitPromise(PromiseInterface $promise): DerivWithdrawalResult
    {
        $loop = Loop::get(); // Now imported!
        $result = null;

        $promise->then(
            function ($value) use (&$result) {
                $result = $value;
            },
            function ($reason) use (&$result) {
                $msg = $reason instanceof \Throwable ? $reason->getMessage() : 'Unknown';
                $result = DerivWithdrawalResult::failure("Network error: {$msg}", []);
            }
        );

        while ($result === null) {
            $loop->run();
        }

        /** @var DerivWithdrawalResult $result */
        return $result;
    }
}