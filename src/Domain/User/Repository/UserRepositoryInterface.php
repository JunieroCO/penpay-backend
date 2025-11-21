<?php
declare(strict_types=1);

namespace PenPay\Domain\User\Repository;

use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\Aggregate\User;
use PenPay\Domain\User\Exception\UserNotFoundException;
use PenPay\Domain\User\ValueObject\Email;

interface UserRepositoryInterface
{
    /**
     * Persist the User aggregate and its uncommitted events
     */
    public function save(User $user): void;

    /**
     * @throws UserNotFoundException
     */
    public function getById(UserId $id): User;

    /**
     * @throws UserNotFoundException
     */
    public function getByDerivLoginId(string $derivLoginId): User;

    /**
     * @throws UserNotFoundException
     */
    public function getByPhone(string $e164PhoneNumber): User;

    /**
     * @throws UserNotFoundException
     */
    public function getByEmail(Email $email): User;

    /**
     * Check existence without loading full aggregate (used in registration)
     */
    public function existsByDerivLoginId(string $derivLoginId): bool;

    public function existsByPhone(string $e164PhoneNumber): bool;

    public function existsByEmail(Email $email): bool;
}