<?php
declare(strict_types=1);

namespace PenPay\Tests\Infrastructure\DerivWsGateway;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use PenPay\Infrastructure\DerivWsGateway\WsClientInterface;
use PenPay\Infrastructure\DerivWsGateway\WsClient;

final class WsClientInterfaceComplianceTest extends TestCase
{
    public function test_ws_client_implements_interface_correctly(): void
    {
        $client = new WsClient(
            $this->createMock(\React\EventLoop\LoopInterface::class),
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );

        // Verify the class implements the interface
        $this->assertInstanceOf(WsClientInterface::class, $client);

        // Verify all interface methods exist with correct signatures
        $this->assertTrue(method_exists($client, 'authorize'));
        $this->assertTrue(method_exists($client, 'sendAndWait'));
        $this->assertTrue(method_exists($client, 'nextReqId'));
        $this->assertTrue(method_exists($client, 'ping'));

        // Test method signatures
        $reflection = new \ReflectionClass($client);
        
        $authorizeMethod = $reflection->getMethod('authorize');
        $this->assertSame('void', (string)$authorizeMethod->getReturnType());
        $this->assertCount(1, $authorizeMethod->getParameters());
        $this->assertSame('string', (string)$authorizeMethod->getParameters()[0]->getType());

        $sendAndWaitMethod = $reflection->getMethod('sendAndWait');
        $this->assertSame(PromiseInterface::class, (string)$sendAndWaitMethod->getReturnType());
        $this->assertCount(2, $sendAndWaitMethod->getParameters());
        $this->assertSame('array', (string)$sendAndWaitMethod->getParameters()[0]->getType());
        $this->assertSame('int', (string)$sendAndWaitMethod->getParameters()[1]->getType());
        $this->assertTrue($sendAndWaitMethod->getParameters()[1]->isDefaultValueAvailable());
        $this->assertSame(20, $sendAndWaitMethod->getParameters()[1]->getDefaultValue());

        $nextReqIdMethod = $reflection->getMethod('nextReqId');
        $this->assertSame('int', (string)$nextReqIdMethod->getReturnType());

        $pingMethod = $reflection->getMethod('ping');
        $this->assertSame('void', (string)$pingMethod->getReturnType());
    }
}