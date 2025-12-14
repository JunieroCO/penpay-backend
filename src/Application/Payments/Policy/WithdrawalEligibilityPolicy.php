<?php
declare(strict_types=1);

namespace PenPay\Application\Payments\Policy;

use PenPay\Application\Payments\Exception\PaymentNotAllowedException;
use PenPay\Domain\Shared\Kernel\UserId;
use PenPay\Domain\User\Repository\UserRepositoryInterface;
use PenPay\Domain\User\ValueObject\UserStatus;
use PenPay\Domain\User\Exception\UserNotFoundException;

final readonly class WithdrawalEligibilityPolicy
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {}

    /**
     * Ensures user is eligible to make a withdrawal
     * 
     * @throws PaymentNotAllowedException
     */
    public function ensureEligible(string $userId, int $amountUsdCents): void
    {
        try {
            $userIdObject = UserId::fromString($userId);
            $user = $this->userRepository->getById($userIdObject);
        } catch (UserNotFoundException $e) {
            throw new PaymentNotAllowedException('User not found');
        }

        $this->assertAccountActive($user->status());

        $this->assertUserVerified($user);

        $this->assertNotSuspended($user);
        
        $this->assertOnboardingComplete($user);
    }

    /**
     * Check if user account is active
     * 
     * @throws PaymentNotAllowedException
     */
    private function assertAccountActive(UserStatus $status): void
    {
        if (!$status->isActive()) {
            throw new PaymentNotAllowedException(
                'Account is not active. Please contact support.'
            );
        }
    }

    /**
     * Check if user is verified (KYC completed)
     * 
     * @throws PaymentNotAllowedException
     */
    private function assertUserVerified($user): void
    {
        if (!$user->isVerified()) {
            throw new PaymentNotAllowedException(
                'Account verification required. Please complete KYC to make withdrawals.'
            );
        }
    }

    /**
     * Check if user account is not suspended or banned
     * 
     * @throws PaymentNotAllowedException
     */
    private function assertNotSuspended($user): void
    {
        if ($user->isSuspended()) {
            throw new PaymentNotAllowedException(
                'Account is suspended. Please contact support.'
            );
        }

        if ($user->isBanned()) {
            throw new PaymentNotAllowedException(
                'Account access has been restricted. Please contact support.'
            );
        }
    }

    /**
     * Check if user has completed onboarding
     * 
     * @throws PaymentNotAllowedException
     */
    private function assertOnboardingComplete($user): void
    {
        if (!$user->hasCompletedOnboarding()) {
            throw new PaymentNotAllowedException(
                'Please complete account setup before making withdrawals.'
            );
        }
    }

    /**
     * Check if user can withdraw without throwing exception
     */
    public function isEligible(string $userId): bool
    {
        try {
            $this->ensureEligible($userId, 0);
            return true;
        } catch (PaymentNotAllowedException) {
            return false;
        }
    }
}