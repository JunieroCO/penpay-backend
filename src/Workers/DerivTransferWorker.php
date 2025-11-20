<?php
declare(strict_types=1);

namespace PenPay\Workers;

use PenPay\Domain\Payments\Entity\DerivTransfer;
use PenPay\Infrastructure\DerivWsGateway\DerivGatewayClient;

final class DerivTransferWorker
{
    public function __construct(
        private DerivGatewayClient $gateway,
        private TransactionRepositoryInterface $txRepo,
        private LedgerRecorder $ledgerRecorder
    ) {}

    public function handleTransferRequested(array $payload): void
    {
        $tx = $this->txRepo->findById($payload['transaction_id']);
        if (!$tx || $tx->getStatus()->isCompleted()) return;

        $response = $this->gateway->transferToDeriv(
            loginId: $payload['deriv_login_id'],
            amountUsd: $payload['usd_cents'] / 100
        );

        $transfer = new DerivTransfer(
            transactionId: $tx->getId(),
            derivAccountId: $payload['deriv_login_id'],
            amountUsd: $tx->getAmount(),
            derivTransferId: $response->transferId,
            derivTxnId: $response->txnId,
            executedAt: new \DateTimeImmutable()
        );

        $tx->completeWithDerivTransfer($transfer);
        $this->txRepo->save($tx);

        $this->ledgerRecorder->recordDepositCompleted($tx);
    }
}