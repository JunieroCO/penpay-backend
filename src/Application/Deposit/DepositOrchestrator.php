<?php
declare(strict_types=1);

namespace PenPay\Application\Deposit;

use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Shared\Kernel\TransactionId;
use PenPay\Domain\Wallet\ValueObject\Money;
use PenPay\Domain\Wallet\ValueObject\Currency;
use PenPay\Domain\Wallet\ValueObject\LockedRate;
use PenPay\Domain\Wallet\Service\DailyLimitChecker;
use PenPay\Domain\Wallet\Service\LedgerRecorder;
use PenPay\Infrastructure\Fx\FxService;
use PenPay\Infrastructure\Queue\RedisStreamPublisher;
use PenPay\Infrastructure\Persistence\TransactionRepositoryInterface;

final readonly class DepositOrchestrator
{
    public function __construct(
        private TransactionRepositoryInterface $transactionRepo,
        private LedgerRecorder $ledgerRecorder,
        private DailyLimitChecker $limitChecker,
        private FxService $fxService,
        private RedisStreamPublisher $publisher
    ) {}

    public function initiateDeposit(
        string $userId,
        int $kesCents,
        IdempotencyKey $idempotencyKey
    ): Transaction {
        // 1. Idempotency + limit check
        $this->limitChecker->assertDailyDepositLimit($userId, $kesCents);

        // 2. Lock FX rate
        $lockedRate = $this->fxService->lockRate(Currency::KES, Currency::USD);
        $usdCents = (int) round($kesCents * $lockedRate->rate);

        // 3. Create aggregates
        $txId = TransactionId::generate();
        $amountKes = new Money($kesCents, Currency::KES);
        $amountUsd = new Money($usdCents, Currency::USD);

        $transaction = Transaction::initiateDeposit($txId, $amountKes, $idempotencyKey);
        $transaction->getRecordedEvents(); // trigger TransactionCreated

        // 4. Persist + publish
        $this->transactionRepo->save($transaction);
        $this->ledgerRecorder->recordDepositInitiated(
            userId: $userId,
            transactionId: $txId,
            amountUsd: $amountUsd,
            amountKes: $amountKes,
            lockedRate: $lockedRate
        );

        $this->publisher->publish('deposit.initiated', [
            'transaction_id' => $txId->value,
            'user_id' => $userId,
            'kes_cents' => $kesCents,
        ]);

        return $transaction;
    }
}