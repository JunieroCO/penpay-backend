<?php
declare(strict_types=1);

namespace PenPay\Application\Payments\Policy;

use PenPay\Application\Payments\Exception\PaymentNotAllowedException;
use PenPay\Domain\Wallet\Services\DailyLimitPolicyInterface;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\Currency;

final readonly class WithdrawalLimitsPolicy
{
    private const MIN_WITHDRAWAL_CENTS = 500;     
    private const MAX_WITHDRAWAL_CENTS = 100000;  

    public function __construct(
        private DailyLimitPolicyInterface $dailyLimitPolicy
    ) {}

    /**
     * Validates all withdrawal limits for a transaction
     * 
     * @throws PaymentNotAllowedException
     */
    public function ensureWithinLimits(string $userId, int $amountUsdCents): void
    {
        $this->assertAboveMinimum($amountUsdCents);

        $this->assertBelowMaximum($amountUsdCents);

        $this->assertWithinDailyLimit($userId, $amountUsdCents);
    }

    /**
     * Check if amount meets minimum withdrawal requirement
     * 
     * @throws PaymentNotAllowedException
     */
    private function assertAboveMinimum(int $amountUsdCents): void
    {
        if ($amountUsdCents < self::MIN_WITHDRAWAL_CENTS) {
            throw new PaymentNotAllowedException(
                sprintf(
                    'Withdrawal amount must be at least $%.2f (received $%.2f)',
                    self::MIN_WITHDRAWAL_CENTS / 100,
                    $amountUsdCents / 100
                )
            );
        }
    }

    /**
     * Check if amount is within maximum single transaction limit
     * 
     * @throws PaymentNotAllowedException
     */
    private function assertBelowMaximum(int $amountUsdCents): void
    {
        if ($amountUsdCents > self::MAX_WITHDRAWAL_CENTS) {
            throw new PaymentNotAllowedException(
                sprintf(
                    'Withdrawal amount cannot exceed $%.2f (received $%.2f)',
                    self::MAX_WITHDRAWAL_CENTS / 100,
                    $amountUsdCents / 100
                )
            );
        }
    }

    /**
     * Check if withdrawal would exceed daily limit
     * 
     * @throws PaymentNotAllowedException
     */
    private function assertWithinDailyLimit(string $userId, int $amountUsdCents): void
    {
        $todayTotal = $this->dailyLimitPolicy->amountWithdrawnToday($userId);
        
        $dailyLimit = $this->dailyLimitPolicy->dailyWithdrawalLimitForUser($userId);
        
        $requestedAmount = new Money($amountUsdCents, Currency::USD);
        
        $projectedTotal = $todayTotal->add($requestedAmount);
        
        if ($projectedTotal->greaterThan($dailyLimit)) {
            $remainingLimit = $dailyLimit->subtract($todayTotal);
            
            throw new PaymentNotAllowedException(
                sprintf(
                    'Daily withdrawal limit exceeded. Limit: $%.2f, Today: $%.2f, Remaining: $%.2f, Requested: $%.2f',
                    $dailyLimit->cents / 100,
                    $todayTotal->cents / 100,
                    max(0, $remainingLimit->cents) / 100,
                    $amountUsdCents / 100
                )
            );
        }
    }

    /**
     * Get remaining daily withdrawal limit for a user
     * 
     * @return array{limit_cents: int, used_cents: int, remaining_cents: int}
     */
    public function getRemainingDailyLimit(string $userId): array
    {
        $todayTotal = $this->dailyLimitPolicy->amountWithdrawnToday($userId);
        $dailyLimit = $this->dailyLimitPolicy->dailyWithdrawalLimitForUser($userId);
        
        $remaining = $dailyLimit->subtract($todayTotal);
        
        return [
            'limit_cents' => $dailyLimit->cents,
            'used_cents' => $todayTotal->cents,
            'remaining_cents' => max(0, $remaining->cents),
        ];
    }

    /**
     * Check if a specific amount can be withdrawn without throwing exception
     */
    public function canWithdraw(string $userId, int $amountUsdCents): bool
    {
        try {
            $this->ensureWithinLimits($userId, $amountUsdCents);
            return true;
        } catch (PaymentNotAllowedException) {
            return false;
        }
    }

    /**
     * Get withdrawal limits information
     * 
     * @return array{min_cents: int, max_cents: int, min_usd: float, max_usd: float}
     */
    public static function getLimits(): array
    {
        return [
            'min_cents' => self::MIN_WITHDRAWAL_CENTS,
            'max_cents' => self::MAX_WITHDRAWAL_CENTS,
            'min_usd' => self::MIN_WITHDRAWAL_CENTS / 100,
            'max_usd' => self::MAX_WITHDRAWAL_CENTS / 100,
        ];
    }
}