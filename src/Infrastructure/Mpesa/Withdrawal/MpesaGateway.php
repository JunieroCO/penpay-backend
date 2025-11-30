<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Mpesa\Withdrawal;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class MpesaGateway implements MpesaGatewayInterface
{
    private const OAUTH_URL = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    private const B2C_URL   = 'https://sandbox.safaricom.co.ke/mpesa/b2c/v1/paymentrequest';

    // Production URLs (uncomment in prod)
    // private const OAUTH_URL = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    // private const B2C_URL   = 'https://api.safaricom.co.ke/mpesa/b2c/v1/paymentrequest';

    public function __construct(
        private readonly PsrClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
        private readonly string $shortCode,
        private readonly string $initiatorName,
        private readonly string $securityCredential,
        private readonly string $resultUrl,
        private readonly string $timeoutUrl,
        private readonly bool $sandbox = true,
        private readonly int $maxRetries = 3,
        private readonly int $cacheTtl = 3300 // 55 minutes (Safaricom token lasts 1 hour)
    ) {}

    public function b2c(string $phoneNumber, int $amountKesCents, string $reference): MpesaB2CResult
    {
        $amountKes = $amountKesCents / 100;

        if ($amountKes < 50) {
            return MpesaB2CResult::failure('Minimum B2C amount is KES 50', []);
        }

        $attempt = 0;

        do {
            $attempt++;

            try {
                $this->logger->info('MpesaGateway: Initiating B2C payment', [
                    'phone'       => $phoneNumber,
                    'amount_kes'  => $amountKes,
                    'reference'   => $reference,
                    'attempt'     => $attempt,
                ]);

                // Create JSON payload
                $payload = [
                    'InitiatorName'      => $this->initiatorName,
                    'SecurityCredential' => $this->securityCredential,
                    'CommandID'          => 'BusinessPayment',
                    'Amount'             => $amountKes,
                    'PartyA'             => $this->shortCode,
                    'PartyB'             => $this->normalizePhone($phoneNumber),
                    'Remarks'            => 'PenPay Withdrawal',
                    'QueueTimeOutURL'    => $this->timeoutUrl,
                    'ResultURL'          => $this->resultUrl,
                    'Occasion'           => substr($reference, 0, 13),
                ];

                // Create request using PSR-18
                $request = $this->requestFactory->createRequest('POST', self::B2C_URL)
                    ->withHeader('Authorization', 'Bearer ' . $this->getValidAccessToken())
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streamFactory->createStream(json_encode($payload)));

                $response = $this->http->sendRequest($request);

                $body = json_decode((string) $response->getBody(), true);

                $this->logger->info('MpesaGateway: B2C request successful', [
                    'conversation_id' => $body['ConversationID'] ?? null,
                    'originator_id'   => $body['OriginatorConversationID'] ?? null,
                ]);

                return MpesaB2CResult::fromMpesaResponse($body);

            } catch (RequestException $e) {
                $response = $e->getResponse();
                $status = $response?->getStatusCode();
                $body = $response ? (string) $response->getBody() : null;

                $this->logger->warning('MpesaGateway: B2C HTTP error', [
                    'attempt' => $attempt,
                    'status'  => $status,
                    'body'    => $body,
                    'error'   => $e->getMessage(),
                ]);

                if ($attempt >= $this->maxRetries || !in_array($status, [500, 502, 503, 504])) {
                    return MpesaB2CResult::failure(
                        "B2C failed after {$attempt} attempts: " . $e->getMessage(),
                        $body ? json_decode($body, true) : []
                    );
                }

                // Exponential backoff
                usleep(500000 * $attempt); // 0.5s, 1s, 1.5s

            } catch (GuzzleException | Throwable $e) {
                $this->logger->error('MpesaGateway: Unexpected error during B2C', [
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);

                if ($attempt >= $this->maxRetries) {
                    return MpesaB2CResult::failure('Network error: ' . $e->getMessage(), []);
                }

                usleep(500000 * $attempt);
            }
        } while ($attempt < $this->maxRetries);

        return MpesaB2CResult::failure('Max retries exceeded', []);
    }

    private function getValidAccessToken(): string
    {
        $cacheKey = 'mpesa.oauth.token';

        if ($this->cache->has($cacheKey)) {
            $token = $this->cache->get($cacheKey);
            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        return $this->fetchAndCacheToken($cacheKey);
    }

    private function fetchAndCacheToken(string $cacheKey): string
    {
        try {
            // Create OAuth request using PSR-18
            $request = $this->requestFactory->createRequest('GET', self::OAUTH_URL)
                ->withHeader('Authorization', 'Basic ' . base64_encode($this->consumerKey . ':' . $this->consumerSecret));

            $response = $this->http->sendRequest($request);

            $data = json_decode((string) $response->getBody(), true);

            if (!isset($data['access_token'])) {
                throw new RuntimeException('No access token in OAuth response');
            }

            $token = $data['access_token'];
            $expiresIn = (int)($data['expires_in'] ?? 3599);

            $this->cache->set($cacheKey, $token, $expiresIn - 300); // Refresh 5 min early

            $this->logger->info('MpesaGateway: OAuth token refreshed', [
                'expires_in' => $expiresIn,
            ]);

            return $token;

        } catch (Throwable $e) {
            $this->logger->critical('MpesaGateway: Failed to obtain OAuth token', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Unable to authenticate with Safaricom', 0, $e);
        }
    }

    private function normalizePhone(string $phone): string
    {
        // Remove all non-digit characters
        $digits = preg_replace('/\D/', '', $phone);
        
        // Remove leading zeros
        $digits = ltrim($digits, '0');
        
        // If it already starts with 254, return as-is
        if (str_starts_with($digits, '254')) {
            return $digits;
        }
        
        // Otherwise prepend 254
        return '254' . $digits;
    }
}