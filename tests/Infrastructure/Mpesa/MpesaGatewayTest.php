<?php
declare(strict_types=1);

namespace PenPay\Tests\Infrastructure\Mpesa;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\SimpleCache\CacheInterface;  
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use PenPay\Infrastructure\Mpesa\Withdrawal\MpesaGateway;
use PenPay\Infrastructure\Mpesa\Withdrawal\MpesaB2CResult;

final class MpesaGatewayTest extends TestCase
{
    private const B2C_URL = 'https://sandbox.safaricom.co.ke/mpesa/b2c/v1/paymentrequest';
    
    private ClientInterface&MockObject $httpClient;
    private RequestFactoryInterface&MockObject $requestFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private CacheInterface&MockObject $cache;
    private LoggerInterface&MockObject $logger;
    private MpesaGateway $gateway;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->requestFactory = $this->createMock(RequestFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Mock OAuth token in cache
        $this->cache->method('has')->willReturn(true);
        $this->cache->method('get')->willReturn('cached-access-token-123');

        $this->gateway = new MpesaGateway(
            http: $this->httpClient,
            requestFactory: $this->requestFactory,
            streamFactory: $this->streamFactory,
            cache: $this->cache,
            logger: $this->logger,
            consumerKey: 'test-key',
            consumerSecret: 'test-secret',
            shortCode: '600000',
            initiatorName: 'testinitiator',
            securityCredential: 'encrypted-cred',
            resultUrl: 'https://example.com/result',
            timeoutUrl: 'https://example.com/timeout',
            sandbox: true,
            maxRetries: 2
        );
    }

    private function mockRequest(?callable $streamValidator = null): RequestInterface&MockObject
    {
        $stream = $this->createMock(StreamInterface::class);
        $request = $this->createMock(RequestInterface::class);

        // Mock the immutable chain: createRequest -> withHeader -> withHeader -> withBody
        $this->requestFactory->method('createRequest')
            ->with('POST', self::B2C_URL)
            ->willReturn($request);

        if ($streamValidator) {
            $this->streamFactory->method('createStream')
                ->with($this->callback($streamValidator))
                ->willReturn($stream);
        } else {
            $this->streamFactory->method('createStream')
                ->willReturn($stream);
        }

        // Each withHeader/withBody call returns the same mock (simulating immutability)
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        return $request;
    }

    public function test_it_returns_success_on_valid_response(): void
    {
        $payload = [
            'ResponseCode' => '0',
            'TransactionReceipt' => 'RB123XYZ',
            'ConversationID' => 'ABC123',
            'OriginatorConversationID' => 'DEF456',
        ];

        $request = $this->mockRequest(function($json) {
            $data = json_decode($json, true);
            // Phone should be normalized to 254712345678 (not 254254712345678)
            return isset($data['PartyB']) && $data['PartyB'] === '254712345678';
        });

        $this->httpClient->expects($this->once())
            ->method('sendRequest')
            ->with($request)
            ->willReturn(new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));

        // Pass phone without country code (0712345678)
        $result = $this->gateway->b2c('0712345678', 100000, 'tx-ref-1');

        $this->assertTrue($result->isSuccess(), "Expected success but got failure: " . ($result->errorMessage() ?? 'Unknown error'));
        $this->assertSame('RB123XYZ', $result->receiptNumber());
        $this->assertSame(0, $result->resultCode());
        $this->assertNull($result->errorMessage());
    }

    public function test_it_returns_failure_on_error_response(): void
    {
        $payload = [
            'ResponseCode' => '1',
            'ResponseDescription' => 'Insufficient funds',
            'ConversationID' => 'ABC123',
            'OriginatorConversationID' => 'DEF456',
        ];

        $request = $this->mockRequest();

        $this->httpClient->method('sendRequest')
            ->willReturn(new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));

        $result = $this->gateway->b2c('0712345678', 100000, 'tx-ref-2');

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Insufficient funds', $result->errorMessage());
        $this->assertSame(1, $result->resultCode());
    }

    public function test_it_retries_on_transient_network_failure(): void
    {
        $request = $this->mockRequest();

        $this->httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willThrowException(new \Exception('Network timeout'));

        $result = $this->gateway->b2c('0712345678', 100000, 'tx-ref-3');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Network timeout', $result->errorMessage());
    }

    public function test_it_succeeds_after_one_retry(): void
    {
        $successPayload = [
            'ResponseCode' => '0',
            'TransactionReceipt' => 'RB999',
            'ConversationID' => 'CONV123',
            'OriginatorConversationID' => 'ORIG123',
        ];

        $request = $this->mockRequest();

        $callCount = 0;
        $this->httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(function () use ($successPayload, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \Exception('Timeout');
                }
                return new Response(200, [], json_encode($successPayload, JSON_THROW_ON_ERROR));
            });

        $result = $this->gateway->b2c('0712345678', 50000, 'tx-ref-4');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('RB999', $result->receiptNumber());
        $this->assertSame(0, $result->resultCode());
    }

    public function test_it_normalizes_phone_number_correctly(): void
    {
        $successPayload = [
            'ResponseCode' => '0',
            'TransactionReceipt' => 'OK123',
            'ConversationID' => 'CONV123',
            'OriginatorConversationID' => 'ORIG123',
        ];

        $request = $this->mockRequest(function($json) {
            $data = json_decode($json, true);
            return $data['PartyB'] === '254712345678';
        });

        $this->httpClient->expects($this->once())
            ->method('sendRequest')
            ->with($request)
            ->willReturn(new Response(200, [], json_encode($successPayload, JSON_THROW_ON_ERROR)));

        // Test with +254 format
        $result = $this->gateway->b2c('+254712345678', 100000, 'ref');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('OK123', $result->receiptNumber());
    }

    public function test_it_rejects_amount_below_minimum(): void
    {
        $result = $this->gateway->b2c('0712345678', 4900, 'tx-ref-5');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Minimum B2C amount is KES 50', $result->errorMessage());
    }
}