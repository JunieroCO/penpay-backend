<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Mpesa;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PenPay\Domain\Shared\Kernel\Utc;  
use RuntimeException;
use InvalidArgumentException;
use DateTimeImmutable;

final class DarajaMpesaClient implements MpesaClientInterface
{
    private const BASE_URL_SANDBOX = 'https://sandbox.safaricom.co.ke';
    private const BASE_URL_PROD    = 'https://api.safaricom.co.ke';

    private Client $http;
    private string $consumerKey;
    private string $consumerSecret;
    private string $shortcode;
    private string $passkey;
    private string $baseUrl;
    private ?string $cachedToken = null;
    private ?DateTimeImmutable $tokenExpiresAt = null;

    public function __construct(
        string $consumerKey,
        string $consumerSecret,
        string $shortcode,
        string $passkey,
        bool $sandbox = true
    ) {
        $this->consumerKey    = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->shortcode      = $shortcode;
        $this->passkey        = $passkey;
        $this->baseUrl        = $sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PROD;
        $this->http           = new Client(['timeout' => 15.0]);
    }

    public function initiateStkPush(
        string $phoneNumber,
        int $amountKesCents,
        string $transactionId,
        string $callbackUrl
    ): object {
        $this->validatePhoneNumber($phoneNumber);
        if ($amountKesCents < 100) { 
            throw new InvalidArgumentException('Amount must be at least 1 KES (100 cents)');
        }

        $token = $this->getValidAccessToken();

        $timestamp = Utc::now()->format('YmdHis');  
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => (string) ($amountKesCents / 100), 
            'PartyA'            => $phoneNumber,
            'PartyB'            => $this->shortcode,
            'PhoneNumber'       => $phoneNumber,
            'CallBackURL'       => $callbackUrl,
            'AccountReference'  => $transactionId,
            'TransactionDesc'   => 'PenPay Deposit',
        ];

        try {
            $response = $this->http->post(
                $this->baseUrl . '/mpesa/stkpush/v1/processrequest',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => $payload,
                ]
            );

            $data = json_decode($response->getBody()->getContents());

            if (!isset($data->CheckoutRequestID)) {
                throw new RuntimeException('Invalid STK Push response: ' . json_encode($data));
            }

            return $data;

        } catch (GuzzleException $e) {
            throw new RuntimeException('M-Pesa STK Push failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function validateCallbackSignature(array $payload, string $signature): bool
    {
        // Safaricom does not currently sign callbacks — future-proofing
        return true;
    }

    public function getAccessToken(): string
    {
        return $this->getValidAccessToken();
    }

    private function getValidAccessToken(): string
    {
        if ($this->cachedToken && $this->tokenExpiresAt && $this->tokenExpiresAt > new DateTimeImmutable()) {
            return $this->cachedToken;
        }

        try {
            $response = $this->http->get(
                $this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials',
                [
                    'auth' => [$this->consumerKey, $this->consumerSecret]
                ]
            );

            $data = json_decode($response->getBody()->getContents());

            $this->cachedToken = $data->access_token;
            $this->tokenExpiresAt = (new DateTimeImmutable())->modify('+55 minutes');

            return $this->cachedToken;

        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get M-Pesa access token', 0, $e);
        }
    }

    private function validatePhoneNumber(string $phone): void
    {
        if (!preg_match('/^254[17]\d{8}$/', $phone)) {
            throw new InvalidArgumentException('Phone number must be in E164 format: 2547xxxxxxxx');
        }
    }
}