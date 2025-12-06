<?php
declare(strict_types=1);

namespace PenPay\Domain\User\Exception;

use RuntimeException;

final class UserNotFoundException extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("User with ID {$id} not found");
    }

    public static function withDerivLoginId(string $loginId): self
    {
        return new self("User with Deriv login ID {$loginId} not found");
    }

    public static function withPhone(string $phone): self
    {
        return new self("User with phone {$phone} not found");
    }

    public static function withEmail(string $email): self
    {
        return new self("User with email {$email} not found");
    }
    public static function withDerivUserId(int $derivUserId): self
    {
        return new self("User with Deriv user ID {$derivUserId} not found");
    }
}