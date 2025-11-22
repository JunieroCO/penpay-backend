<?php
declare(strict_types=1);

namespace PenPay\Application\Auth\DTO;

use PenPay\Domain\User\ValueObject\Email;
use InvalidArgumentException;

final readonly class LoginRequest
{
    private function __construct(
        public Email   $email,
        public string  $password,
        public ?string $deviceId = null,
        public ?string $userAgent = null,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data, ?string $userAgent = null): self
    {
        $email    = $data['email']    ?? throw new InvalidArgumentException('Email is required');
        $password = $data['password'] ?? throw new InvalidArgumentException('Password is required');
        $deviceId = $data['device_id'] ?? null;

        if (!is_string($email) || $email === '') {
            throw new InvalidArgumentException('Email must be a non-empty string');
        }
        if (!is_string($password) || trim($password) === '') {
            throw new InvalidArgumentException('Password must be a non-empty string');
        }
        if ($deviceId !== null && !is_string($deviceId)) {
            throw new InvalidArgumentException('device_id must be a string or null');
        }

        return new self(
            email:     Email::fromString($email),     // Domain-level validation
            password:  $password,
            deviceId:  $deviceId,
            userAgent: $userAgent,
        );
    }
}