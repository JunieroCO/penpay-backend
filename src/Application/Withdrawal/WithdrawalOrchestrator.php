<?php
declare(strict_types=1);

namespace PenPay\Application\Withdrawal;

use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\Services\DailyLimitCheckerInterface;
use PenPay\Domain\Wallet\Services\LedgerRecorderInterface;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Infrastructure\Fx\FxServiceInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use RuntimeException;
use PenPay\Domain\Payments\ValueObject\TransactionType;

final readonly class WithdrawalOrchestrator
{
    public function __construct(
        private TransactionRepositoryInterface $txRepo,
        private DailyLimitCheckerInterface $dailyLimitChecker,
        private FxServiceInterface $fxService,
        private LedgerRecorderInterface $ledgerRecorder,
        private RedisStreamPublisherInterface $publisher,
        private OneTimeSecretStoreInterface $secretStore,
    ) {}

    /**
     * Called when user submits the 8-character Deriv verification code
     */
    public function confirmWithdrawal(
        string $userId,
        int $usdCents,
        string $verificationCode,
        IdempotencyKey $idempotencyKey
    ): Transaction {
        // 1. IDEMPOTENCY — First and final defense
        if ($existing = $this->txRepo->findByIdempotencyKey($idempotencyKey)) {
            // Verify it's actually a withdrawal transaction
            if ($existing->getType() !== TransactionType::WITHDRAWAL) {
                throw new RuntimeException('Idempotency key collision - wrong transaction type');
            }
            return $existing;
        }

        // 2. DAILY LIMIT — Hard guard
        $amount = Money::usd($usdCents);
        if (!$this->dailyLimitChecker->canWithdraw($userId, $amount)) {
            throw new RuntimeException('Daily withdrawal limit exceeded');
        }

        // 3. LOCK FX RATE — For KES payout later
        $lockedRate = $this->fxService->lockRate('USD', 'KES');

        // 4. CREATE TRANSACTION — Using the unified Transaction aggregate
        $tx = Transaction::initiateWithdrawal(
            id: TransactionId::generate(),
            amountUsd: Money::usd($usdCents),
            idempotencyKey: $idempotencyKey
        );

        // 5. ENCRYPT VERIFICATION CODE — ONE-TIME SECRET
        $secretKey = 'verif_' . bin2hex(random_bytes(12));
        $this->secretStore->store(
            key: $secretKey,
            value: $verificationCode,
            ttlSeconds: 600 // 10 minutes
        );

        // 6. LEDGER — Append-only truth
        $this->ledgerRecorder->recordWithdrawalInitiated(
            userId: $userId,
            transactionId: $tx->getId(),
            amountUsd: Money::usd($usdCents),
            lockedRate: $lockedRate
        );

        // 7. PERSIST
        $this->txRepo->save($tx);

        // 8. PUBLISH INFRA EVENT — NO PLAIN CODE
        $this->publisher->publish('withdrawals.initiated', [
            'transaction_id' => (string) $tx->getId(),
            'user_id'        => $userId,
            'usd_cents'      => $usdCents,
            'secret_key'     => $secretKey,
            'rate'           => $lockedRate->rate(),
            'expires_at'     => $lockedRate->expiresAt()->format('c'),
        ]);

        return $tx;
    }
}